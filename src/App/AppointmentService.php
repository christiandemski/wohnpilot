<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/** One-off appointments at the home (`typ: termin`, folder `termine/`): craftsmen, chimney sweep, viewing. */
final class AppointmentService
{
    private const PAST_SHOWN = 10;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>} upcoming soonest first, past newest first */
    public function list(): array
    {
        $today = $this->clock->today();
        $upcoming = [];
        $past = [];
        foreach ($this->all() as $item) {
            $item['date'] >= $today ? $upcoming[] = $item : $past[] = $item;
        }
        usort($upcoming, static fn (array $a, array $b): int => [$a['date'], $a['time'] ?? ''] <=> [$b['date'], $b['time'] ?? '']);
        usort($past, static fn (array $a, array $b): int => [$b['date'], $b['time'] ?? ''] <=> [$a['date'], $a['time'] ?? '']);
        return ['upcoming' => $upcoming, 'past' => array_slice($past, 0, self::PAST_SHOWN)];
    }

    /** @return list<array<string, mixed>> every appointment with a valid date */
    public function all(): array
    {
        $today = $this->clock->today();
        $items = [];
        foreach ($this->objects->all('termin') as $stored) {
            $item = $this->data($stored, $today);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    public function detail(string $id): array
    {
        $stored = $this->objects->find('termin', $id, 'Termin');
        return ($this->data($stored, $this->clock->today()) ?? throw new AppException('invalid_note', 409, 'Appointment without a valid date'))
            + ['notes' => trim(NoteDocument::parse($stored->content)->body()->text())];
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $stored = $this->objects->create('termin', 'termine', $fields['titel'] . ' ' . substr($fields['datum'], 0, 7), $fields, $user);
        return $this->detail((string) NoteDocument::parse($stored->content)->frontmatter()->get('id'));
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $this->notes->updateFields($this->objects->find('termin', $id, 'Termin'), self::validate($changes, false), $original, $etag, $user->id);
        return $this->detail($id);
    }

    private function data(StoredNote $stored, string $today): ?array
    {
        $fm = NoteDocument::parse($stored->content)->frontmatter();
        $date = $fm->get('datum');
        if (!is_string($date) || !Day::isValid($date)) {
            return null;
        }
        $time = $fm->get('uhrzeit');
        return [
            'id' => (string) $fm->get('id', ''),
            'path' => $stored->path,
            'etag' => $stored->etag,
            'title' => (string) $fm->get('titel', $fm->get('id', '')),
            'date' => $date,
            'time' => is_string($time) ? $time : null,
            'daysUntil' => Day::between($today, $date),
            'fields' => $fm->all(),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('titel', required: true)
            ->date('datum', required: true)
            ->time('uhrzeit')
            ->time('bis')
            ->string('ort')
            ->slugList('bezieht_sich_auf');
        if (is_string($from = $check->value('uhrzeit')) && is_string($to = $check->value('bis')) && $to <= $from) {
            $check->error('bis', 'after_start');
        }
        return $check->fields();
    }
}
