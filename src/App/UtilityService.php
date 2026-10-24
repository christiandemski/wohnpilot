<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Domain\Urgency;
use Wohnpilot\Domain\UtilityStatement;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Utility statements of the active home (`typ: nebenkosten` in
 * `objekte/<objekt>/nebenkosten/`): list, detail with comparison and
 * meter check, writing, and their dates for "Was steht an".
 */
final class UtilityService
{
    /** Show a missing statement from 30 days before the delivery deadline until 90 days after */
    private const MISSING_FROM_DAYS = 30;
    private const MISSING_UNTIL_DAYS = 90;
    private const PAYMENT_LEAD_DAYS = 14;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly MeterService $meters,
        private readonly LeaseService $lease,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> newest period first */
    public function list(): array
    {
        $today = $this->clock->today();
        return array_map(fn (array $s): array => $this->summary($s[0], $s[1], $today), $this->statements());
    }

    public function detail(string $id): array
    {
        $today = $this->clock->today();
        $all = $this->statements();
        $index = null;
        foreach ($all as $i => [$stored, $statement]) {
            if ($statement->id === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw AppException::notFound("Nebenkosten $id");
        }
        [$stored, $statement] = $all[$index];
        $previous = null;
        foreach (array_slice($all, $index + 1) as [, $older]) {
            if ($older->kind === $statement->kind && $older->to < $statement->to) {
                $previous = $older;
                break;
            }
        }
        $object = $this->household->activeObject()['id'] ?? '';
        $heating = array_values(array_map(
            static fn (array $s): array => ['id' => $s[1]->id, 'title' => $s[1]->title(), 'total' => $s[1]->total()],
            array_filter($all, static fn (array $s): bool => $s[1]->billedIn === $statement->id),
        ));

        return $this->summary($stored, $statement, $today) + [
            'positions' => $statement->positions,
            'comparison' => $statement->compareWith($previous),
            'previous' => $previous === null ? null : ['id' => $previous->id, 'title' => $previous->title(), 'days' => $previous->days()],
            'checks' => $statement->checkAgainst(array_values($this->meters->metersById($object))),
            'heating' => $heating,
            'taxShares' => $statement->taxShares(),
            'prepaymentAdopted' => $this->prepaymentAdopted($statement),
            'notes' => MaintenanceService::freeText(NoteDocument::parse($stored->content)),
        ];
    }

    /** @param array<string, mixed> $input fields, plus `positionen` as a list */
    public function create(array $input, User $user): array
    {
        $object = $this->household->activeObject() ?? throw new AppException('no_object', 409, 'Create an object first');
        // All problems at once: the fields and the positions
        $errors = [];
        foreach ([fn () => self::validate($input, true), fn () => self::positions($input['positionen'] ?? [])] as $i => $check) {
            try {
                $i === 0 ? $fields = $check() : $positions = $check();
            } catch (AppException $e) {
                if ($e->errorCode !== 'validation') {
                    throw $e;
                }
                $errors += (array) ($e->details['fields'] ?? []);
            }
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        $year = substr((string) $fields['bis'], 0, 4);
        $id = Slug::unique(
            $fields['art'] . '-' . $year,
            fn (string $slug): bool => $this->notes->findById('nebenkosten', $slug) !== null || $this->notes->exists("objekte/{$object['id']}/nebenkosten/$slug.md"),
        );
        $front = ['typ' => 'nebenkosten', 'id' => $id, 'objekt' => $object['id']] + $fields + ['erstellt_von' => $user->id];
        $note = self::withPositions(NoteDocument::create($front), $positions);
        $this->notes->create("objekte/{$object['id']}/nebenkosten/$id.md", $note);
        return $this->detail($id);
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
     * Replaces the positions. If they changed elsewhere since $original
     * (e.g. in the Notes app), nothing is written and both are shown.
     *
     * @param list<array<string, mixed>> $positions
     * @param list<array<string, mixed>> $original
     */
    public function setPositions(string $id, array $positions, array $original, User $user): array
    {
        $mine = self::positions($positions);
        $base = self::positions($original);
        $stored = $this->find($id);
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($mine, $base, $user): NoteDocument {
            $theirs = self::positions(UtilityStatement::fromNote($note)->positions, true);
            if (self::same($theirs, $mine)) {
                return $note;
            }
            if (!self::same($theirs, $base)) {
                throw new AppException('conflict', 409, 'Changed elsewhere', ['fields' => ['positionen' => ['mine' => $mine, 'theirs' => $theirs]]]);
            }
            $note = self::withPositions($note, $mine);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->detail($id);
    }

    /** Notes the day you paid the back payment, or takes it back with null. */
    public function setPaid(string $id, ?string $date, User $user): array
    {
        if ($date !== null && !Day::isValid($date)) {
            throw AppException::validation(['datum' => 'date']);
        }
        $this->notes->modifyLatest($this->find($id)->path, static function (NoteDocument $note) use ($date, $user): NoteDocument {
            if ($note->frontmatter()->get('bezahlt') === $date) {
                return $note;
            }
            $date === null ? $note->frontmatter()->remove('bezahlt') : $note->frontmatter()->set('bezahlt', $date);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        return $this->detail($id);
    }

    /** Puts the statement's new prepayment into the lease (LeaseService::changeUtilities). */
    public function adoptPrepayment(string $id, User $user): array
    {
        $this->find($id);
        $statement = null;
        foreach ($this->all() as $s) {
            if ($s->id === $id) {
                $statement = $s;
            }
        }
        if ($statement?->newPrepayment === null || $statement->newPrepaymentFrom === null) {
            throw AppException::validation(['neue_vorauszahlung_eur' => 'required']);
        }
        $this->lease->changeUtilities($statement->newPrepaymentFrom, $statement->newPrepayment, $statement->title(), $user);
        return $this->detail($id);
    }

    /**
     * Dates for "Was steht an": back payment due, objection deadline in its
     * last 30 days, and the next statement of a kind when it is late.
     *
     * @return list<array<string, mixed>>
     */
    public function agendaItems(string $today): array
    {
        $items = [];
        $latest = [];
        foreach ($this->statements() as [, $s]) {
            $ref = ['type' => 'nebenkosten', 'id' => $s->id];
            $latest[$s->kind] ??= $s;
            if (($due = $s->paymentDue()) !== null) {
                $days = Day::between($today, $due);
                $items[] = ['ref' => $ref, 'name' => $s->title(), 'category' => null, 'kind' => 'nachzahlung', 'date' => $due, 'daysUntil' => $days, 'status' => Urgency::of($days, self::PAYMENT_LEAD_DAYS), 'amount' => $s->balance()];
            }
            $objection = $s->objectionDeadline();
            if ($objection !== null) {
                $days = Day::between($today, $objection);
                if ($days >= 0 && $days <= UtilityStatement::OBJECTION_LEAD_DAYS) {
                    $items[] = ['ref' => $ref, 'name' => $s->title(), 'category' => null, 'kind' => 'einwand', 'date' => $objection, 'daysUntil' => $days, 'status' => Urgency::of($days, UtilityStatement::OBJECTION_LEAD_DAYS)];
                }
            }
        }
        // The landlord's next statement, one year after the last one
        $last = $latest['betriebskosten'] ?? null;
        if ($last !== null && $last->to !== '') {
            $nextTo = Day::periodEnd(Day::addDays($last->to, 1), 12);
            $next = new UtilityStatement('', $last->object, 'betriebskosten', Day::addDays($last->to, 1), $nextTo, null, null, null, null, null, null, [], []);
            $deadline = $next->deliveryDeadline();
            $days = Day::between($today, $deadline);
            if ($today > $nextTo && $days <= self::MISSING_FROM_DAYS && $days >= -self::MISSING_UNTIL_DAYS) {
                $items[] = ['ref' => ['type' => 'nebenkosten', 'id' => null], 'name' => $next->title(), 'category' => null, 'kind' => 'abrechnung', 'date' => $deadline, 'daysUntil' => $days, 'status' => Urgency::of($days, self::MISSING_FROM_DAYS), 'periodFrom' => $next->from, 'periodTo' => $nextTo];
            }
        }
        return $items;
    }

    /** @return list<UtilityStatement> all statements of the active home, newest period first */
    public function all(): array
    {
        return array_column($this->statements(), 1);
    }

    /** @return list<array{StoredNote, UtilityStatement}> */
    private function statements(): array
    {
        $object = $this->household->activeObject();
        if ($object === null) {
            return [];
        }
        $list = array_map(
            static fn (StoredNote $s): array => [$s, UtilityStatement::fromNote(NoteDocument::parse($s->content))],
            $this->notes->byType('nebenkosten', $object['id']),
        );
        usort($list, static fn (array $a, array $b): int => [$b[1]->to, $a[1]->kind] <=> [$a[1]->to, $b[1]->kind]);
        return $list;
    }

    private function summary(StoredNote $stored, UtilityStatement $s, string $today): array
    {
        $fm = NoteDocument::parse($stored->content)->frontmatter();
        $objection = $s->objectionDeadline();
        $due = $s->paymentDue();
        return [
            'id' => $s->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'title' => $s->title(),
            'kind' => $s->kind,
            'fields' => $fm->all(),
            'from' => $s->from,
            'to' => $s->to,
            'days' => $s->days(),
            'received' => $s->received,
            'total' => $s->total(),
            'balance' => $s->balance(),
            'billedIn' => $s->billedIn,
            'deliveryDeadline' => $s->to === '' ? null : $s->deliveryDeadline(),
            'deliveredLate' => $s->deliveredLate(),
            'objection' => $objection === null ? null : ['date' => $objection, 'daysUntil' => Day::between($today, $objection)],
            'payment' => $due === null ? null : ['date' => $due, 'daysUntil' => Day::between($today, $due)],
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /** The new prepayment is in the lease's rent history from its day on */
    private function prepaymentAdopted(UtilityStatement $s): ?bool
    {
        if ($s->newPrepayment === null || $s->newPrepaymentFrom === null) {
            return null;
        }
        $lease = $this->lease->lease();
        if ($lease === null) {
            return false;
        }
        $rent = $lease->rentOn($s->newPrepaymentFrom);
        return $rent['utilities'] !== null && abs($rent['utilities'] - $s->newPrepayment) < 0.005;
    }

    private function find(string $id): StoredNote
    {
        return $this->notes->findById('nebenkosten', $id) ?? throw AppException::notFound("Nebenkosten $id");
    }

    /**
     * Positions as sent by the app (`kostenart`, `betrag`, `steuer`, `steuerbetrag`)
     * or as read (`kind`, …), in one shape; empty rows are dropped.
     *
     * @return list<array{kind: string, amount: float, taxKind: ?string, taxAmount: ?float}>
     */
    private static function positions(mixed $rows, bool $read = false): array
    {
        if (!is_array($rows)) {
            throw AppException::validation(['positionen' => 'list']);
        }
        $result = [];
        $errors = [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $kind = trim((string) ($row['kind'] ?? $row['kostenart'] ?? ''));
            $amount = $row['amount'] ?? $row['betrag'] ?? null;
            $taxKind = $row['taxKind'] ?? $row['steuer'] ?? null;
            $taxAmount = $row['taxAmount'] ?? $row['steuerbetrag'] ?? null;
            if ($kind === '' && ($amount === null || $amount === '')) {
                continue;
            }
            if ($kind === '') {
                $errors["positionen.$i.kostenart"] = 'required';
            }
            if (!is_int($amount) && !is_float($amount)) {
                $errors["positionen.$i.betrag"] = 'number';
            }
            $taxKind = $taxKind === null || $taxKind === '' ? null : (string) $taxKind;
            if ($taxKind !== null && !in_array($taxKind, UtilityStatement::TAX_KINDS, true)) {
                $errors["positionen.$i.steuer"] = 'unknown';
            }
            if ($taxAmount !== null && $taxAmount !== '' && !is_int($taxAmount) && !is_float($taxAmount)) {
                $errors["positionen.$i.steuerbetrag"] = 'number';
            }
            $result[] = [
                'kind' => $kind,
                'amount' => is_numeric($amount) ? round((float) $amount, 2) : 0.0,
                'taxKind' => $taxKind,
                'taxAmount' => $taxKind === null || !is_numeric($taxAmount) ? null : round((float) $taxAmount, 2),
            ];
        }
        if ($errors !== [] && !$read) {
            throw AppException::validation($errors);
        }
        return $result;
    }

    private static function same(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    /**
     * The note with exactly these positions in its table; other columns of
     * the table and the text below it stay, and so do the rows up to the
     * first change, as they were written.
     *
     * @param list<array{kind: string, amount: float, taxKind: ?string, taxAmount: ?float}> $positions
     */
    private static function withPositions(NoteDocument $note, array $positions): NoteDocument
    {
        $rows = array_map(static fn (array $p): array => [
            'Kostenart' => $p['kind'],
            'Betrag €' => Reading::formatNumber($p['amount'], 2),
            '§ 35a' => (string) $p['taxKind'],
            'davon § 35a €' => $p['taxAmount'] === null ? '' : Reading::formatNumber($p['taxAmount'], 2),
        ], $positions);
        if ($rows === [] && $note->body()->table() === null) {
            return $note;
        }
        $note = HistoryTable::ensure($note, UtilityStatement::COLUMNS, $rows[0] ?? ['Kostenart' => '']);
        $table = $note->body()->table();
        $keep = 0;
        foreach ($table->rows() as $i => $existing) {
            $current = self::positions([[
                'kostenart' => $existing['Kostenart'] ?? '',
                'betrag' => Reading::parseNumber($existing['Betrag €'] ?? ''),
                'steuer' => trim($existing['§ 35a'] ?? ''),
                'steuerbetrag' => Reading::parseNumber($existing['davon § 35a €'] ?? ''),
            ]], true);
            if (!isset($positions[$i]) || !self::same($current, [$positions[$i]])) {
                break;
            }
            $keep = $i + 1;
        }
        for ($i = $table->count() - 1; $i >= $keep; $i--) {
            $table->removeRow($i);
        }
        for ($i = $keep, $n = count($rows); $i < $n; $i++) {
            $table->insertRow($rows[$i], $i);
        }
        return $note;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->enum('art', UtilityStatement::KINDS, required: true)
            ->date('von', required: true)
            ->date('bis', required: true)
            ->date('zugang')
            ->slug('kontakt')
            ->string('abrechner')
            ->slug('abrechnung')
            ->number('vorauszahlungen_eur')
            ->number('neue_vorauszahlung_eur')
            ->date('neue_vorauszahlung_ab')
            ->date('bezahlt')
            ->string('paperless');
        $from = $check->value('von');
        $to = $check->value('bis');
        if (is_string($from) && is_string($to) && $to < $from) {
            $check->error('bis', 'after_start');
        }
        if (array_key_exists('verbrauch', $input)) {
            $consumption = [];
            foreach (is_array($input['verbrauch']) ? $input['verbrauch'] : [] as $key => $value) {
                if (!isset(UtilityStatement::CONSUMPTION[$key])) {
                    $check->error('verbrauch', 'unknown');
                } elseif ($value !== null && $value !== '') {
                    if (!is_int($value) && !is_float($value)) {
                        $check->error('verbrauch', 'number');
                    } else {
                        $consumption[$key] = $value;
                    }
                }
            }
            $check->set('verbrauch', $consumption === [] ? null : $consumption);
        }
        return $check->fields();
    }
}
