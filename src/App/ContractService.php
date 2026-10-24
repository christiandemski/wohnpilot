<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Contract;
use Wohnpilot\Domain\ContractTerm;
use Wohnpilot\Domain\Day;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Contracts and insurances (`typ: vertrag`, folder `vertraege/`). They belong
 * to the household and move with it. Term, deadlines and costs are derived.
 */
final class ContractService
{
    public const CATEGORIES = ['energie', 'internet', 'mobilfunk', 'rundfunk', 'streaming', 'versicherung', 'mitgliedschaft', 'sonstiges'];
    public const RHYTHMS = ['monatlich', 'quartalsweise', 'halbjaehrlich', 'jaehrlich', 'einmalig'];
    /** Columns of a new price history; existing notes keep theirs. */
    public const PRICE_COLUMNS = [Contract::PRICE_COLUMN, 'Kosten €', 'Hinweis'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> by category, then name */
    public function list(): array
    {
        $today = $this->clock->today();
        $contracts = array_map(fn (StoredNote $s): array => $this->summary($s, $today), $this->notes->byType('vertrag'));
        usort($contracts, static fn (array $a, array $b): int => [$a['ended'], $a['fields']['kategorie'] ?? '', mb_strtolower($a['name'])] <=> [$b['ended'], $b['fields']['kategorie'] ?? '', mb_strtolower($b['name'])]);
        return $contracts;
    }

    /** @return list<Contract> contracts that have not ended */
    public function active(): array
    {
        $today = $this->clock->today();
        $contracts = [];
        foreach ($this->notes->byType('vertrag') as $stored) {
            $contract = Contract::fromNote(NoteDocument::parse($stored->content));
            if ($contract->term($today)->state !== ContractTerm::ENDED) {
                $contracts[] = $contract;
            }
        }
        return $contracts;
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $note = NoteDocument::parse($stored->content);
        $table = $note->body()->table();

        return $this->summary($stored, $this->clock->today()) + [
            'prices' => $table === null ? null : ['columns' => $table->headers(), 'rows' => $table->rows()],
            'notes' => trim($note->body()->text()),
        ];
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $base = Slug::from((string) ($fields['bezeichnung'] ?? ''))
            ?: Slug::from(($fields['sparte'] ?? $fields['kategorie']) . ' ' . $fields['anbieter']);
        $id = Slug::unique($base ?: 'vertrag', fn (string $slug): bool => $this->notes->findById('vertrag', $slug) !== null || $this->notes->exists("vertraege/$slug.md"));

        $frontmatter = ['typ' => 'vertrag', 'id' => $id] + $fields + ['erstellt_von' => $user->id];
        $this->notes->create("vertraege/$id.md", NoteDocument::create($frontmatter));
        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $changes = self::validate($changes, false);
        $this->notes->updateFields($this->find($id), $changes, $original, $etag, $user->id);
        return $this->detail($id);
    }

    /**
     * Adds a row to the price history (newest on top), e.g. an announced
     * price change. A row for the same date replaces nothing: it is added once.
     *
     * @param array<string, mixed> $input gueltig_ab, values (column => text)
     */
    public function addPrice(string $id, array $input, User $user): array
    {
        $from = (string) ($input['gueltig_ab'] ?? '');
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        if (!Day::isValid($from)) {
            throw AppException::validation(['gueltig_ab' => 'date']);
        }
        $row = [Contract::PRICE_COLUMN => $from];
        foreach ($values as $column => $value) {
            if (!is_string($column) || $column === Contract::PRICE_COLUMN || !is_scalar($value)) {
                throw AppException::validation(['values' => 'text']);
            }
            $row[$column] = trim((string) $value);
        }

        $stored = $this->find($id);
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($row, $user): NoteDocument {
            $note = HistoryTable::ensure($note, self::PRICE_COLUMNS, $row);
            $table = $note->body()->table();
            $unknown = array_diff(array_keys($row), $table->headers());
            if ($unknown !== []) {
                throw AppException::validation(['values' => 'unknown_column']);
            }
            $existing = HistoryTable::find($table, Contract::PRICE_COLUMN, $row[Contract::PRICE_COLUMN]);
            if ($existing !== null && array_intersect_key($table->row($existing), $row) == $row) {
                return $note;
            }
            HistoryTable::insertNewestFirst($table, Contract::PRICE_COLUMN, $row);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->detail($id);
    }

    private function find(string $id): StoredNote
    {
        return $this->notes->findById('vertrag', $id) ?? throw AppException::notFound("Vertrag $id");
    }

    private function summary(StoredNote $stored, string $today): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $contract = Contract::fromNote($note);
        $term = $contract->term($today);

        return [
            'id' => $contract->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'name' => self::name($fm->all()),
            'fields' => $fm->all(),
            'insurance' => $contract->isInsurance(),
            'monthlyCost' => $contract->monthlyCost(),
            'term' => $term->toArray(),
            'ended' => $term->state === ContractTerm::ENDED,
            'priceChanges' => $contract->priceChanges($today),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /** Own name, else provider: "Stadtwerke". */
    public static function name(array $fields): string
    {
        return trim((string) ($fields['bezeichnung'] ?? '')) ?: trim((string) ($fields['anbieter'] ?? '')) ?: (string) ($fields['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->enum('kategorie', self::CATEGORIES, required: true)
            ->string('sparte')
            ->string('anbieter', required: true)
            ->string('bezeichnung')
            ->string('vertragsnummer')
            ->number('kosten_eur')
            ->enum('rhythmus', self::RHYTHMS, default: 'monatlich')
            ->date('beginn')
            ->int('mindestlaufzeit_monate', 0, 240)
            ->int('verlaengerung_monate', 0, 120)
            ->int('kuendigungsfrist_wochen', 0, 104)
            ->int('kuendigungsfrist_monate', 0, 24)
            ->date('gekuendigt_am')
            ->date('gekuendigt_zum')
            ->int('vorlauf_tage', 0, 365)
            ->string('paperless')
            ->number('versicherungssumme_eur')
            ->number('selbstbeteiligung_eur')
            ->slugList('bezieht_sich_auf');

        // One notice period; the form sends the other one as null to remove it.
        if ($check->value('kuendigungsfrist_wochen') !== null && $check->value('kuendigungsfrist_monate') !== null) {
            $check->error('kuendigungsfrist_monate', 'one_of');
        }
        if (is_numeric($paperless = $check->value('paperless'))) {
            $check->set('paperless', (int) $paperless);
        }
        return $check->fields();
    }
}
