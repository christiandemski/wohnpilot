<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Lease;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Domain\RentChange;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * The lease of the active object (`typ: mietvertrag`, one per object in
 * `objekte/<objekt>/mietvertrag/`). Rent in force and deadlines are derived.
 */
final class LeaseService
{
    public const RENT_TYPES = ['standard', 'index', 'staffel'];
    public const DEPOSIT_TYPES = ['kautionskonto', 'barkaution', 'buergschaft', 'verpfaendung', 'sonstiges'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
    ) {
    }

    /** The lease of the active object, or null if there is none yet. */
    public function get(): ?array
    {
        $stored = $this->find();
        return $stored === null ? null : $this->data($stored);
    }

    /** @return Lease|null for computing deadlines elsewhere */
    public function lease(): ?Lease
    {
        $stored = $this->find();
        return $stored === null ? null : Lease::fromNote(NoteDocument::parse($stored->content));
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): array
    {
        $object = $this->household->activeObject() ?? throw new AppException('no_object', 409, 'Create an object first');
        if ($this->find() !== null) {
            throw new AppException('exists', 409, 'This object has a lease already');
        }
        $fields = self::validate($input, true);
        $id = "mietvertrag-{$object['id']}";
        $frontmatter = ['typ' => 'mietvertrag', 'id' => $id, 'objekt' => $object['id']] + $fields + ['erstellt_von' => $user->id];
        $note = NoteDocument::create($frontmatter);
        if (isset($fields['beginn'])) {
            // The first row of the history: rent at the start.
            $row = self::rentRow($fields['beginn'], $fields['kaltmiete_eur'] ?? null, $fields['nebenkosten_eur'] ?? null, 'Einzug', null);
            $note = HistoryTable::ensure($note, Lease::COLUMNS, $row);
            HistoryTable::insertNewestFirst($note->body()->table(), 'Gültig ab', $row);
        }
        $this->notes->create("objekte/{$object['id']}/mietvertrag/mietvertrag.md", $note);
        return $this->get();
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(array $changes, array $original, string $etag, User $user): array
    {
        $stored = $this->find() ?? throw AppException::notFound('Mietvertrag');
        $this->notes->updateFields($stored, self::validate($changes, false), $original, $etag, $user->id);
        return $this->get();
    }

    /**
     * Adds a rent change, e.g. a letter asking for more rent (with `eingang`).
     * Sending the same change twice adds it once.
     *
     * @param array<string, mixed> $input gueltig_ab, kaltmiete_eur, nebenkosten_eur, grund, eingang
     */
    public function addRentChange(array $input, User $user): array
    {
        $fields = (new Input($input, true))
            ->date('gueltig_ab', required: true)
            ->number('kaltmiete_eur')
            ->number('nebenkosten_eur')
            ->string('grund')
            ->date('eingang')
            ->fields();
        if (!isset($fields['kaltmiete_eur']) && !isset($fields['nebenkosten_eur'])) {
            throw AppException::validation(['kaltmiete_eur' => 'required']);
        }
        $row = self::rentRow($fields['gueltig_ab'], $fields['kaltmiete_eur'] ?? null, $fields['nebenkosten_eur'] ?? null, $fields['grund'] ?? null, $fields['eingang'] ?? null);

        $stored = $this->find() ?? throw AppException::notFound('Mietvertrag');
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($row, $user): NoteDocument {
            $note = HistoryTable::ensure($note, Lease::COLUMNS, $row);
            $table = $note->body()->table();
            $existing = HistoryTable::find($table, 'Gültig ab', $row['Gültig ab']);
            if ($existing !== null && array_intersect_key($table->row($existing), $row) == $row) {
                return $note;
            }
            HistoryTable::insertNewestFirst($table, 'Gültig ab', $row);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->get();
    }

    /**
     * A new utilities prepayment from $from on, e.g. after a statement: a row
     * with the cold rent in force then. Later rows that still carry the old
     * prepayment (copied along, e.g. with a rent increase) get the new one
     * too, so it does not jump back. Applying it twice changes nothing.
     */
    public function changeUtilities(string $from, float $amount, string $reason, User $user): array
    {
        if (!Day::isValid($from)) {
            throw AppException::validation(['gueltig_ab' => 'date']);
        }
        $stored = $this->find() ?? throw AppException::notFound('Mietvertrag');
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($from, $amount, $reason, $user): NoteDocument {
            $lease = Lease::fromNote($note);
            $before = $lease->rentOn($from);
            if ($before['validFrom'] === $from && $before['utilities'] !== null && abs($before['utilities'] - $amount) < 0.005) {
                return $note;
            }
            $old = $before['utilities'];
            $row = self::rentRow($from, $before['cold'], $amount, $reason, null);
            $note = HistoryTable::ensure($note, Lease::COLUMNS, $row);
            $table = $note->body()->table();
            $same = HistoryTable::find($table, 'Gültig ab', $from);
            if ($same !== null) {
                $table->replaceRow($same, ['Nebenkosten €' => $row['Nebenkosten €']]);
            } else {
                HistoryTable::insertNewestFirst($table, 'Gültig ab', $row);
            }
            foreach ($table->rows() as $index => $later) {
                $change = RentChange::fromRow($later);
                if ($change !== null && $change->validFrom > $from && $old !== null && $change->utilities !== null && abs($change->utilities - $old) < 0.005) {
                    $table->replaceRow($index, ['Nebenkosten €' => $row['Nebenkosten €']]);
                }
            }
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->get();
    }

    /** Notes that you agreed to the rent change valid from $validFrom (or clears it with null). */
    public function agree(string $validFrom, ?string $date, User $user): array
    {
        if ($date !== null && !Day::isValid($date)) {
            throw AppException::validation(['datum' => 'date']);
        }
        $stored = $this->find() ?? throw AppException::notFound('Mietvertrag');
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($validFrom, $date, $user): NoteDocument {
            $table = $note->body()->table();
            $index = $table === null ? null : HistoryTable::find($table, 'Gültig ab', $validFrom);
            if ($index === null) {
                throw AppException::notFound("Mietänderung $validFrom");
            }
            if (!in_array('Zugestimmt', $table->headers(), true)) {
                throw new AppException('no_column', 409, 'The rent table has no column "Zugestimmt"');
            }
            if (trim($table->row($index)['Zugestimmt']) !== (string) $date) {
                $table->replaceRow($index, ['Zugestimmt' => (string) $date]);
                $note->frontmatter()->set('geaendert_von', $user->id);
            }
            return $note;
        });
        return $this->get();
    }

    private function find(): ?StoredNote
    {
        $object = $this->household->activeObject();
        if ($object === null) {
            return null;
        }
        return $this->notes->byType('mietvertrag', $object['id'])[0] ?? null;
    }

    private function data(StoredNote $stored): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $lease = Lease::fromNote($note);
        $today = $this->clock->today();

        return [
            'id' => $lease->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'fields' => $fm->all(),
            'rent' => $lease->rentOn($today),
            'history' => array_map(static fn ($c): array => (array) $c, $lease->history),
            'consentDeadlines' => $lease->consentDeadlines($today),
            'tenantNotice' => $lease->tenantNotice($today),
            'notes' => MaintenanceService::freeText($note),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /** @return array<string, string> a row of the rent history; amounts with cents only if they have some */
    private static function rentRow(string $validFrom, int|float|null $cold, int|float|null $utilities, ?string $reason, ?string $received): array
    {
        $amount = static fn (int|float|null $v): ?string => $v === null ? null : Reading::formatNumber((float) $v, floor($v) === (float) $v ? 0 : 2);
        return array_filter([
            'Gültig ab' => $validFrom,
            'Kaltmiete €' => $amount($cold),
            'Nebenkosten €' => $amount($utilities),
            'Grund' => $reason,
            'Eingang' => $received,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('vermieter')
            ->date('beginn')
            ->enum('mietart', self::RENT_TYPES, default: 'standard')
            ->number('kaltmiete_eur')
            ->number('nebenkosten_eur')
            ->int('kuendigungsfrist_monate', 1, 24)
            ->string('paperless')
            ->slug('kontakt');

        if (array_key_exists('kaution', $input)) {
            $deposit = is_array($input['kaution']) ? $input['kaution'] : [];
            $parts = (new Input($deposit, true))
                ->number('betrag_eur')
                ->enum('art', self::DEPOSIT_TYPES)
                ->string('hinterlegt');
            try {
                $map = $parts->fields();
            } catch (AppException $e) {
                foreach ($e->details['fields'] as $key => $problem) {
                    $check->error("kaution.$key", $problem);
                }
                $map = [];
            }
            $check->set('kaution', $map === [] ? null : $map);
        }
        if (is_numeric($paperless = $check->value('paperless'))) {
            $check->set('paperless', (int) $paperless);
        }
        return $check->fields();
    }
}
