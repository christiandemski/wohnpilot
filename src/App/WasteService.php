<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use RuntimeException;
use Wohnpilot\Calendar\CalendarFetcher;
use Wohnpilot\Calendar\IcsReader;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\WasteSchedule;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\AttachmentStore;
use Wohnpilot\Storage\StoredNote;

/**
 * Waste collection of the active home (`typ: muell`, `termine/muell.md`).
 * The note holds the address of the municipal calendar (`kalender_url`) or,
 * if the municipality only offers a download, the uploaded file next to the
 * attachments (`kalender_datei`), and the bins. The calendar itself is a
 * cache file (var/cache), loaded again after six hours. If loading fails,
 * the last copy is used and the error is shown.
 */
final class WasteService
{
    public const COLORS = ['bin-rest', 'bin-bio', 'bin-papier', 'bin-gelb', 'bin-glas'];
    public const MAX_AGE_SECONDS = 6 * 3600;
    /** After a failed load, wait this long before the next try (the overview should not hang on it). */
    public const RETRY_SECONDS = 30 * 60;
    /** How far ahead pickups are listed. */
    public const DAYS_AHEAD = 60;
    /** How far ahead the end of the calendar is looked for (a downloaded file usually covers one year). */
    private const END_LOOKAHEAD_DAYS = 400;
    private const DEFAULT_BINS = [
        ['id' => 'rest', 'name' => 'Restmüll', 'farbe' => 'bin-rest', 'stichwort' => 'Rest'],
        ['id' => 'bio', 'name' => 'Bio', 'farbe' => 'bin-bio', 'stichwort' => 'Bio'],
        ['id' => 'papier', 'name' => 'Papier', 'farbe' => 'bin-papier', 'stichwort' => 'Papier'],
        ['id' => 'gelb', 'name' => 'Gelbe Tonne', 'farbe' => 'bin-gelb', 'stichwort' => 'Gelb'],
    ];

    /** @param \Closure(): int|null $clock seconds since epoch, replaceable in tests */
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly CalendarFetcher $fetcher,
        private readonly string $cacheDir,
        private readonly Clock $clock,
        private readonly ?\Closure $now = null,
        private readonly ?AttachmentStore $files = null,
    ) {
    }

    /**
     * @return array{configured: bool, id: ?string, etag: ?string, fullService: bool, url: ?string, bins: list<array<string, string>>,
     *   pickups: list<array{date: string, time: ?string, summary: string, bin: ?string}>, next: array<string, string>,
     *   fetchedAt: ?int, error: ?string}
     */
    public function get(bool $refresh = false): array
    {
        $stored = $this->find();
        $fm = $stored === null ? [] : NoteDocument::parse($stored->content)->frontmatter()->all();
        $bins = is_array($fm['tonnen'] ?? null) ? array_values(array_filter($fm['tonnen'], 'is_array')) : [];
        $url = is_string($fm['kalender_url'] ?? null) ? $fm['kalender_url'] : null;
        $file = is_string($fm['kalender_datei'] ?? null) ? $fm['kalender_datei'] : null;
        $today = $this->clock->today();

        [$ics, $fetchedAt, $error] = match (true) {
            $url !== null => $this->calendar("url:$url", fn (): string => $this->fetcher->fetch($url), $refresh),
            $file !== null && $this->files !== null => $this->calendar("datei:$file", fn (): string => $this->readFile($file), $refresh),
            default => [null, null, null],
        };
        $pickups = $ics === null ? [] : WasteSchedule::pickups($bins, IcsReader::occurrences($ics, $today, Day::addDays($today, self::DAYS_AHEAD)));
        $all = $ics === null ? [] : IcsReader::occurrences($ics, $today, Day::addDays($today, self::END_LOOKAHEAD_DAYS));

        return [
            'configured' => $stored !== null,
            'id' => $fm['id'] ?? null,
            'etag' => $stored?->etag,
            'fullService' => ($fm['vollservice'] ?? false) === true,
            'url' => $url,
            'file' => $url === null ? $file : null,
            // Last pickup in the calendar: a downloaded file needs a new one before then
            'calendarEnds' => $all === [] ? null : $all[array_key_last($all)]['date'],
            'bins' => $bins === [] && $stored === null ? self::DEFAULT_BINS : $bins,
            'pickups' => $pickups,
            'next' => WasteSchedule::nextPerBin($pickups, $today),
            'fetchedAt' => $fetchedAt,
            'error' => $error,
        ];
    }

    /**
     * Saves address, full service and bins; creates `termine/muell.md` on first use.
     *
     * @param array<string, mixed> $input kalender_url, vollservice, tonnen
     */
    public function save(array $input, User $user, bool $raw = false): array
    {
        if ($raw) {
            $fields = $input;
        } else {
            $check = (new Input($input, false))->url('kalender_url')->bool('vollservice');
            if (array_key_exists('tonnen', $input)) {
                $check->set('tonnen', self::bins($input['tonnen'], $check));
            }
            $fields = $check->fields();
            // An address replaces an uploaded file
            if (is_string($fields['kalender_url'] ?? null)) {
                $fields['kalender_datei'] = null;
            }
        }

        $stored = $this->find();
        if ($stored === null) {
            $fields += ['vollservice' => false, 'tonnen' => self::DEFAULT_BINS];
            $this->objects->create('muell', 'termine', 'muell', array_filter($fields, static fn ($v) => $v !== null), $user);
        } else {
            $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($fields, $user): NoteDocument {
                foreach ($fields as $key => $value) {
                    $value === null ? $note->frontmatter()->remove($key) : $note->frontmatter()->set($key, $value);
                }
                $note->frontmatter()->set('geaendert_von', $user->id);
                return $note;
            });
        }
        return $this->get(true);
    }

    /**
     * Takes a downloaded calendar file (ICS) instead of an address: stored in
     * the Nextcloud at anhaenge/muell/<objekt>/abfuhrkalender.ics, replacing
     * an older one. The address, if there was one, is removed.
     */
    public function upload(string $ics, User $user): array
    {
        $files = $this->files ?? throw new AppException('no_files', 409, 'No attachment storage');
        if (strlen($ics) > CalendarFetcher::MAX_BYTES) {
            throw AppException::validation(['kalender' => 'too_large']);
        }
        if (!str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw AppException::validation(['kalender' => 'not_a_calendar']);
        }
        $path = AttachmentStore::FOLDER . '/muell/' . $this->objects->activeObjectId() . '/abfuhrkalender.ics';
        $files->putFile($path, $ics);
        // The new file counts from now on, not the copy of the old one
        @unlink($this->cacheFile("datei:$path"));
        $this->save(['kalender_url' => null, 'kalender_datei' => $path], $user, raw: true);
        return $this->get();
    }

    private function readFile(string $path): string
    {
        try {
            return $this->files->getFile($path);
        } catch (\Throwable) {
            throw new RuntimeException('file_missing');
        }
    }

    private function find(): ?StoredNote
    {
        return $this->objects->all('muell')[0] ?? null;
    }

    private function cacheFile(string $source): string
    {
        return $this->cacheDir . '/abfuhr-' . sha1($source) . '.ics';
    }

    /**
     * @param \Closure(): string $load throws RuntimeException with a short reason
     * @return array{?string, ?int, ?string} calendar text, when it was loaded, why loading failed
     */
    private function calendar(string $source, \Closure $load, bool $refresh): array
    {
        $file = $this->cacheFile($source);
        $cachedAt = is_file($file) ? (int) filemtime($file) : null;
        $now = $this->now === null ? time() : ($this->now)();
        if (!$refresh && $cachedAt !== null && $now - $cachedAt < self::MAX_AGE_SECONDS) {
            return [(string) file_get_contents($file), $cachedAt, null];
        }
        $failed = $file . '.fehler';
        if (!$refresh && is_file($failed) && $now - (int) filemtime($failed) < self::RETRY_SECONDS) {
            return [$cachedAt === null ? null : (string) file_get_contents($file), $cachedAt, trim((string) file_get_contents($failed))];
        }
        try {
            $ics = $load();
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0775, true);
            }
            file_put_contents($file, $ics, LOCK_EX);
            touch($file, $now);
            @unlink($failed);
            return [$ics, $now, null];
        } catch (RuntimeException $e) {
            if (is_dir($this->cacheDir) || mkdir($this->cacheDir, 0775, true)) {
                file_put_contents($failed, $e->getMessage());
                touch($failed, $now);
            }
            return [$cachedAt === null ? null : (string) file_get_contents($file), $cachedAt, $e->getMessage()];
        }
    }

    /** @return list<array<string, string>> */
    private static function bins(mixed $value, Input $check): array
    {
        $bins = [];
        foreach (is_array($value) ? $value : [] as $i => $bin) {
            $name = trim((string) ($bin['name'] ?? ''));
            $color = (string) ($bin['farbe'] ?? '');
            if ($name === '' || !in_array($color, self::COLORS, true)) {
                $check->error("tonnen.$i", $name === '' ? 'required' : 'unknown');
                continue;
            }
            $id = Slug::from((string) ($bin['id'] ?? '')) ?: Slug::from($name);
            $keyword = trim((string) ($bin['stichwort'] ?? ''));
            $bins[] = ['id' => $id, 'name' => $name, 'farbe' => $color] + ($keyword === '' ? [] : ['stichwort' => $keyword]);
        }
        return $bins;
    }
}
