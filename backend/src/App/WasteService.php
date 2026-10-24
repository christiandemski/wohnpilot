<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use RuntimeException;
use Wohnpilot\Calendar\CalendarFetcher;
use Wohnpilot\Calendar\IcsReader;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\WasteSchedule;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Waste collection of the active home (`typ: muell`, `termine/muell.md`).
 * The note holds the address of the municipal calendar and the bins; the
 * calendar itself is a cache file (var/cache), loaded again after six hours.
 * If loading fails, the last copy is used and the error is shown.
 */
final class WasteService
{
    public const COLORS = ['bin-rest', 'bin-bio', 'bin-papier', 'bin-gelb', 'bin-glas'];
    public const MAX_AGE_SECONDS = 6 * 3600;
    /** How far ahead pickups are listed. */
    public const DAYS_AHEAD = 60;
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
        $today = $this->clock->today();

        [$ics, $fetchedAt, $error] = $url === null ? [null, null, null] : $this->calendar($url, $refresh);
        $pickups = $ics === null ? [] : WasteSchedule::pickups($bins, IcsReader::occurrences($ics, $today, Day::addDays($today, self::DAYS_AHEAD)));

        return [
            'configured' => $stored !== null,
            'id' => $fm['id'] ?? null,
            'etag' => $stored?->etag,
            'fullService' => ($fm['vollservice'] ?? false) === true,
            'url' => $url,
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
    public function save(array $input, User $user): array
    {
        $check = (new Input($input, false))->url('kalender_url')->bool('vollservice');
        if (array_key_exists('tonnen', $input)) {
            $check->set('tonnen', self::bins($input['tonnen'], $check));
        }
        $fields = $check->fields();

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

    private function find(): ?StoredNote
    {
        return $this->objects->all('muell')[0] ?? null;
    }

    /** @return array{?string, ?int, ?string} calendar text, when it was loaded, why loading failed */
    private function calendar(string $url, bool $refresh): array
    {
        $file = $this->cacheDir . '/abfuhr-' . sha1($url) . '.ics';
        $cachedAt = is_file($file) ? (int) filemtime($file) : null;
        $now = $this->now === null ? time() : ($this->now)();
        if (!$refresh && $cachedAt !== null && $now - $cachedAt < self::MAX_AGE_SECONDS) {
            return [(string) file_get_contents($file), $cachedAt, null];
        }
        try {
            $ics = $this->fetcher->fetch($url);
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0775, true);
            }
            file_put_contents($file, $ics, LOCK_EX);
            touch($file, $now);
            return [$ics, $now, null];
        } catch (RuntimeException $e) {
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
