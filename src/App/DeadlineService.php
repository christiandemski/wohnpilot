<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Deadline;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * One-off deadlines (`typ: frist`): of the active object in
 * `objekte/<objekt>/fristen/`, of the household in `fristen/`.
 * Whether one is done follows from `erledigt` or from linked meter readings.
 */
final class DeadlineService
{
    private const DONE_SHOWN = 20;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly MeterService $meters,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{open: list<array<string, mixed>>, done: list<array<string, mixed>>} open by date, done newest first */
    public function list(): array
    {
        $open = [];
        $done = [];
        foreach ($this->all() as $item) {
            if ($item['evaluation']['status'] === Deadline::DONE) {
                $done[] = $item;
            } else {
                $open[] = $item;
            }
        }
        usort($open, static fn (array $a, array $b): int => [$a['fields']['datum'], $a['id']] <=> [$b['fields']['datum'], $b['id']]);
        usort($done, static fn (array $a, array $b): int => $b['evaluation']['doneOn'] <=> $a['evaluation']['doneOn']);
        return ['open' => $open, 'done' => array_slice($done, 0, self::DONE_SHOWN)];
    }

    /** @return list<array<string, mixed>> every deadline of the active object and the household */
    public function all(): array
    {
        $objectId = $this->household->activeObject()['id'] ?? null;
        $meters = $objectId === null ? [] : $this->meters->metersById($objectId);
        $today = $this->clock->today();
        $items = [];
        foreach ($this->notes->byType('frist') as $stored) {
            $item = $this->data($stored, $meters, $today);
            if ($item !== null && ($item['object'] === '' || $item['object'] === $objectId)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $object = (string) NoteDocument::parse($stored->content)->frontmatter()->get('objekt', '');
        $meters = $object === '' ? [] : $this->meters->metersById($object);
        $note = NoteDocument::parse($stored->content);
        return ($this->data($stored, $meters, $this->clock->today()) ?? throw new AppException('invalid_note', 409, 'Deadline note without a valid date'))
            + ['notes' => MaintenanceService::freeText($note)];
    }

    /** @param array<string, mixed> $input titel, datum, ab, vorlauf_tage, bezieht_sich_auf, haushalt (not bound to the object) */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $object = empty($input['haushalt']) ? ($this->household->activeObject()['id'] ?? null) : null;
        $folder = $object === null ? 'fristen' : "objekte/$object/fristen";
        $id = Slug::unique(
            Slug::from($fields['titel']) ?: 'frist',
            fn (string $slug): bool => $this->notes->findById('frist', $slug) !== null || $this->notes->exists("$folder/$slug.md"),
        );

        $frontmatter = ['typ' => 'frist', 'id' => $id] + ($object === null ? [] : ['objekt' => $object]) + $fields + ['erstellt_von' => $user->id];
        $this->notes->create("$folder/$id.md", NoteDocument::create($frontmatter));
        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $stored = $this->find($id);
        $changes = self::validate($changes, false, NoteDocument::parse($stored->content)->frontmatter()->all());
        $this->notes->updateFields($stored, $changes, $original, $etag, $user->id);
        return $this->detail($id);
    }

    /** Marks the deadline done on $date, or open again with null. Safe to send twice. */
    public function setDone(string $id, ?string $date, User $user): array
    {
        if ($date !== null && !Day::isValid($date)) {
            throw AppException::validation(['erledigt' => 'date']);
        }
        $this->notes->modifyLatest($this->find($id)->path, static function (NoteDocument $note) use ($date, $user): NoteDocument {
            $fm = $note->frontmatter();
            if ($fm->get('erledigt') === $date) {
                return $note;
            }
            $date === null ? $fm->remove('erledigt') : $fm->set('erledigt', $date);
            $fm->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->detail($id);
    }

    private function find(string $id): StoredNote
    {
        return $this->notes->findById('frist', $id) ?? throw AppException::notFound("Frist $id");
    }

    /** @param array<string, \Wohnpilot\Domain\Meter> $meters */
    private function data(StoredNote $stored, array $meters, string $today): ?array
    {
        $note = NoteDocument::parse($stored->content);
        $deadline = Deadline::fromNote($note);
        if ($deadline === null) {
            return null;
        }
        $fm = $note->frontmatter();
        return [
            'id' => $deadline->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'object' => $deadline->object,
            'title' => $deadline->title,
            'fields' => $fm->all(),
            'windowStart' => $deadline->windowStart(),
            'evaluation' => $deadline->evaluate($today, $meters),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current the note's fields when updating, to check `ab` against `datum`
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating, array $current = []): array
    {
        $check = (new Input($input, $creating))
            ->string('titel', required: true)
            ->date('datum', required: true)
            ->date('ab')
            ->int('vorlauf_tage', 0, 365)
            ->slugList('bezieht_sich_auf')
            ->date('erledigt');
        $date = $check->has('datum') ? $check->value('datum') : ($current['datum'] ?? null);
        $from = $check->has('ab') ? $check->value('ab') : ($current['ab'] ?? null);
        if (is_string($from) && is_string($date) && $from > $date) {
            $check->error('ab', 'after_date');
        }
        return $check->fields();
    }
}
