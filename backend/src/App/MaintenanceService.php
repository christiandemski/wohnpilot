<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\MaintenanceSuggestions;
use Wohnpilot\Domain\MaintenanceTask;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Recurring tasks of the active home (`typ: wartung`, folder `wartung/`).
 * Done dates go into the history table, the next due date is derived.
 */
final class MaintenanceService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> by due date */
    public function list(): array
    {
        $today = $this->clock->today();
        $tasks = array_map(fn (StoredNote $s): array => $this->summary($s, $today), $this->objects->all('wartung'));
        // Tasks done by radio need nothing from you: last.
        usort($tasks, static fn (array $a, array $b): int => [$a['evaluation']['automatic'], $a['evaluation']['dueDate'], $a['title']] <=> [$b['evaluation']['automatic'], $b['evaluation']['dueDate'], $b['title']]);
        return $tasks;
    }

    /** @return list<MaintenanceTask> */
    public function tasks(): array
    {
        return array_map(static fn (StoredNote $s): MaintenanceTask => MaintenanceTask::fromNote(NoteDocument::parse($s->content)), $this->objects->all('wartung'));
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $note = NoteDocument::parse($stored->content);
        $history = [];
        foreach ($note->body()->table()?->rows() ?? [] as $row) {
            $history[] = ['date' => trim($row['Datum'] ?? ''), 'by' => trim($row['Erledigt von'] ?? ''), 'note' => trim($row['Notiz'] ?? '')];
        }
        return $this->summary($stored, $this->clock->today()) + [
            'history' => $history,
            'photos' => PhotoService::of($stored),
            'notes' => self::freeText($note),
        ];
    }

    /** @return list<array<string, mixed>> tasks that fit the home and are not there yet */
    public function suggestions(): array
    {
        $object = $this->household->activeObject() ?? throw new AppException('no_object', 409, 'Create an object first');
        $existing = array_map(static fn (StoredNote $s): string => (string) NoteDocument::parse($s->content)->frontmatter()->get('id'), $this->objects->all('wartung'));
        return MaintenanceSuggestions::for($object, $existing);
    }

    /**
     * A new task, or one from the suggestions (`vorschlag`: its id), which keeps
     * the suggestion's id so it is not suggested again.
     *
     * @param array<string, mixed> $input
     */
    public function create(array $input, User $user): array
    {
        if (isset($input['vorschlag'])) {
            $suggestion = current(array_filter($this->suggestions(), static fn (array $s): bool => $s['id'] === $input['vorschlag']))
                ?: throw AppException::notFound('Vorschlag');
            $fields = self::validate(array_filter(
                ['titel' => $suggestion['titel'], 'intervall_monate' => $suggestion['intervall_monate'], 'zustaendig' => $suggestion['zustaendig'], 'saison' => $suggestion['saison'], 'raum' => $suggestion['raum']],
                static fn ($v) => $v !== null,
            ), true);
            $stored = $this->objects->create('wartung', 'wartung', $suggestion['id'], $fields, $user);
        } else {
            $fields = self::validate($input, true);
            $stored = $this->objects->create('wartung', 'wartung', (string) $fields['titel'], $fields, $user);
        }
        return $this->detail((string) NoteDocument::parse($stored->content)->frontmatter()->get('id'));
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $this->notes->updateFields($this->find($id), self::validate($changes, false), $original, $etag, $user->id);
        return $this->detail($id);
    }

    /**
     * Notes the task as done (newest row on top). The same day by the same
     * person twice adds one row.
     *
     * @param array<string, mixed> $input datum (default today), notiz
     */
    public function done(string $id, array $input, User $user): array
    {
        $fields = (new Input($input, true))->date('datum')->string('notiz')->fields();
        $row = ['Datum' => $fields['datum'] ?? $this->clock->today(), 'Erledigt von' => $user->id, 'Notiz' => $fields['notiz'] ?? ''];
        if ($row['Datum'] > $this->clock->today()) {
            throw AppException::validation(['datum' => 'future']);
        }
        $this->notes->modifyLatest($this->find($id)->path, static function (NoteDocument $note) use ($row, $user): NoteDocument {
            $note = HistoryTable::ensure($note, MaintenanceTask::HISTORY_COLUMNS, $row);
            $table = $note->body()->table();
            foreach ($table->rows() as $existing) {
                if (trim($existing['Datum'] ?? '') === $row['Datum'] && trim($existing['Erledigt von'] ?? '') === $row['Erledigt von']) {
                    return $note;
                }
            }
            HistoryTable::insertNewestFirst($table, 'Datum', $row);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->detail($id);
    }

    /** Takes back a done row of this person on $date, e.g. a tap by mistake. */
    public function undo(string $id, string $date, User $user): array
    {
        $this->notes->modifyLatest($this->find($id)->path, static function (NoteDocument $note) use ($date, $user): NoteDocument {
            $table = $note->body()->table();
            foreach ($table?->rows() ?? [] as $index => $row) {
                if (trim($row['Datum'] ?? '') === $date && trim($row['Erledigt von'] ?? '') === $user->id) {
                    $table->removeRow($index);
                    $note->frontmatter()->set('geaendert_von', $user->id);
                    break;
                }
            }
            return $note;
        });
        return $this->detail($id);
    }

    private function find(string $id): StoredNote
    {
        return $this->objects->find('wartung', $id, 'Wartung');
    }

    private function summary(StoredNote $stored, string $today): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $task = MaintenanceTask::fromNote($note);
        return [
            'id' => $task->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'title' => $task->title,
            'fields' => $fm->all(),
            'evaluation' => $task->evaluate($today),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /** Text below the history, without image lines (those are the photos). */
    public static function freeText(NoteDocument $note): string
    {
        return trim((string) preg_replace('/^\s*!\[[^\]\n]*\]\([^)]*\)\s*$/m', '', $note->body()->text()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('titel', required: true)
            ->int('intervall_monate', 1, 120)
            ->int('intervall_wochen', 1, 520)
            ->season('saison')
            ->date('ab')
            ->enum('zustaendig', MaintenanceTask::RESPONSIBLE, default: 'mieter')
            ->enum('ausfuehrung', MaintenanceTask::EXECUTIONS)
            ->string('raum')
            ->int('vorlauf_tage', 0, 365)
            ->string('pflicht')
            ->slugList('bezieht_sich_auf');
        if ($check->value('intervall_monate') !== null && $check->value('intervall_wochen') !== null) {
            $check->error('intervall_wochen', 'one_of');
        }
        if ($creating && $check->value('intervall_monate') === null && $check->value('intervall_wochen') === null) {
            $check->error('intervall_monate', 'required');
        }
        return $check->fields();
    }
}
