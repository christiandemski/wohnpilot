<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Defect;
use Wohnpilot\Domain\MinorRepairs;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Defects of the active home (`typ: mangel`, folder `maengel/`). Each status
 * change is a row of the history; status and dates are derived from it.
 */
final class DefectService
{
    public const RESPONSIBLE = ['vermieter', 'mieter'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> open ones first (oldest first), then fixed ones (newest first) */
    public function list(): array
    {
        $today = $this->clock->today();
        $items = array_map(fn (StoredNote $s): array => $this->summary($s, $today), $this->objects->all('mangel'));
        usort($items, static fn (array $a, array $b): int => $a['evaluation']['open'] !== $b['evaluation']['open']
            ? ($a['evaluation']['open'] ? -1 : 1)
            : ($a['evaluation']['open']
                ? [$a['evaluation']['discovered'], $a['title']] <=> [$b['evaluation']['discovered'], $b['title']]
                : [$b['evaluation']['fixedOn'], $a['title']] <=> [$a['evaluation']['fixedOn'], $b['title']]));
        return $items;
    }

    /** @return list<Defect> */
    public function defects(): array
    {
        return array_map(static fn (StoredNote $s): Defect => Defect::fromNote(NoteDocument::parse($s->content)), $this->objects->all('mangel'));
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $note = NoteDocument::parse($stored->content);
        return $this->summary($stored, $this->clock->today()) + [
            'history' => Defect::fromNote($note)->history,
            'photos' => PhotoService::of($stored),
            'notes' => MaintenanceService::freeText($note),
        ];
    }

    /**
     * A new defect, discovered today unless `entdeckt` says otherwise.
     *
     * @param array<string, mixed> $input titel, raum, zustaendig, kleinreparatur, kosten_eur, nachfassen, entdeckt, notiz
     */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $first = (new Input($input, true))->date('entdeckt')->string('notiz')->fields();
        $discovered = $first['entdeckt'] ?? $this->clock->today();
        if ($discovered > $this->clock->today()) {
            throw AppException::validation(['entdeckt' => 'future']);
        }
        if (($fields['kleinreparatur'] ?? null) === false) {
            unset($fields['kleinreparatur']);
        }
        $row = ['Datum' => $discovered, 'Status' => 'entdeckt', 'Kanal' => '', 'Notiz' => $first['notiz'] ?? ''];
        $stored = $this->objects->create('mangel', 'maengel', (string) $fields['titel'], $fields, $user);
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($row): NoteDocument {
            $note = HistoryTable::ensure($note, Defect::HISTORY_COLUMNS, $row);
            HistoryTable::insertNewestFirst($note->body()->table(), 'Datum', $row);
            return $note;
        });
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
     * A new status (reported, confirmed, appointment, fixed), optionally with
     * a new follow-up day. The same status on the same day twice adds one row.
     *
     * @param array<string, mixed> $input status, datum (default today), kanal, notiz, nachfassen (date or null)
     */
    public function addStatus(string $id, array $input, User $user): array
    {
        $fields = (new Input($input, true))
            ->enum('status', Defect::STATUSES, required: true)
            ->date('datum')
            ->string('kanal')
            ->string('notiz')
            ->fields();
        $followUp = array_key_exists('nachfassen', $input) ? (new Input(['nachfassen' => $input['nachfassen']], false))->date('nachfassen')->fields() : [];
        $row = ['Datum' => $fields['datum'] ?? $this->clock->today(), 'Status' => $fields['status'], 'Kanal' => $fields['kanal'] ?? '', 'Notiz' => $fields['notiz'] ?? ''];
        if ($row['Datum'] > $this->clock->today()) {
            throw AppException::validation(['datum' => 'future']);
        }

        $this->notes->modifyLatest($this->find($id)->path, static function (NoteDocument $note) use ($row, $followUp, $user): NoteDocument {
            $note = HistoryTable::ensure($note, Defect::HISTORY_COLUMNS, $row);
            $table = $note->body()->table();
            $known = array_filter($table->rows(), static fn (array $r): bool => trim($r['Datum'] ?? '') === $row['Datum'] && trim($r['Status'] ?? '') === $row['Status']);
            $changed = false;
            if ($known === []) {
                HistoryTable::insertNewestFirst($table, 'Datum', $row);
                $changed = true;
            }
            if ($followUp !== [] && $note->frontmatter()->get('nachfassen') !== $followUp['nachfassen']) {
                $followUp['nachfassen'] === null ? $note->frontmatter()->remove('nachfassen') : $note->frontmatter()->set('nachfassen', $followUp['nachfassen']);
                $changed = true;
            }
            if ($changed) {
                $note->frontmatter()->set('geaendert_von', $user->id);
            }
            return $note;
        });
        return $this->detail($id);
    }

    /** Minor repairs of $year against the limits of the home's lease (`kleinreparatur_grenze_eur`, `…_jahresgrenze_eur`). */
    public function minorRepairs(int $year): array
    {
        $object = $this->household->activeObject() ?? [];
        $limit = static fn (string $key): ?float => is_int($object[$key] ?? null) || is_float($object[$key] ?? null) ? (float) $object[$key] : null;
        return MinorRepairs::forYear($this->defects(), $year, $limit('kleinreparatur_grenze_eur'), $limit('kleinreparatur_jahresgrenze_eur'));
    }

    private function find(string $id): StoredNote
    {
        return $this->objects->find('mangel', $id, 'Mangel');
    }

    private function summary(StoredNote $stored, string $today): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $defect = Defect::fromNote($note);
        return [
            'id' => $defect->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'title' => $defect->title,
            'fields' => $fm->all(),
            'evaluation' => $defect->evaluate($today),
            'photoCount' => count(PhotoService::of($stored)),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        return (new Input($input, $creating))
            ->string('titel', required: true)
            ->string('raum')
            ->enum('zustaendig', self::RESPONSIBLE, default: 'vermieter')
            ->bool('kleinreparatur')
            ->number('kosten_eur')
            ->date('nachfassen')
            ->slug('kontakt')
            ->fields();
    }
}
