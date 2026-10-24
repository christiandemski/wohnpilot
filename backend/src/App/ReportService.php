<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Consumption;
use Wohnpilot\Domain\ContractTerm;
use Wohnpilot\Domain\Contract;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\GermanNumber;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\MinorRepairs;
use Wohnpilot\Note\NoteDocument;

/**
 * The year in review: consumption, housing costs, statements, work done in
 * the house, and the § 35a amounts for the tax return, plus that list as CSV.
 * All derived from the notes, nothing stored.
 *
 * § 35a EStG: 20 % of the labour costs reduce the income tax, at most
 * 4.000 € for household services and 1.200 € for tradesmen. Amounts from a
 * utility statement count in the year it was received (allowed for tenants).
 */
final class ReportService
{
    public const TAX_RATE = 0.2;
    /** Maximum reduction per kind */
    public const TAX_CAPS = ['haushaltsnah' => 4000.0, 'handwerker' => 1200.0];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly MeterService $meters,
        private readonly LeaseService $lease,
        private readonly UtilityService $utilities,
        private readonly DefectService $defects,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<int> years with data, newest first */
    public function years(): array
    {
        $current = (int) substr($this->clock->today(), 0, 4);
        $first = $current;
        $object = $this->household->activeObject();
        if ($object !== null) {
            foreach ($this->meters->metersById($object['id']) as $meter) {
                if ($meter->readings !== []) {
                    $first = min($first, (int) substr($meter->readings[0]->date, 0, 4));
                }
            }
            $start = $this->lease->lease()?->start;
            if ($start !== null) {
                $first = min($first, (int) substr($start, 0, 4));
            }
        }
        foreach ($this->utilities->all() as $s) {
            if ($s->from !== '') {
                $first = min($first, (int) substr($s->from, 0, 4));
            }
        }
        return range($current, $first);
    }

    public function year(int $year): array
    {
        if ($year < 2000 || $year > (int) substr($this->clock->today(), 0, 4)) {
            throw AppException::notFound("Jahr $year");
        }
        $from = sprintf('%04d-01-01', $year);
        $next = sprintf('%04d-01-01', $year + 1);
        $object = $this->household->activeObject();

        return [
            'year' => $year,
            'complete' => $next <= $this->clock->today(),
            'meters' => $object === null ? [] : $this->consumption($object['id'], $year),
            'housing' => $this->housing($year),
            'statements' => $this->statements($year),
            'maintenance' => $object === null ? [] : $this->maintenance($object['id'], $year),
            'defects' => $this->fixedDefects($year),
            'minorRepairs' => $this->defects->minorRepairs($year),
            'tax' => $this->tax($year),
            'period' => ['from' => $from, 'to' => Day::addDays($next, -1)],
        ];
    }

    /**
     * The § 35a list as CSV for a spreadsheet or tax program: semicolons,
     * German numbers, UTF-8 with BOM so Excel reads the umlauts.
     *
     * @return array{string, string} content and file name
     */
    public function taxCsv(int $year): array
    {
        $tax = $this->year($year)['tax'];
        $kinds = ['haushaltsnah' => 'Haushaltsnahe Dienstleistung', 'handwerker' => 'Handwerkerleistung'];
        $cell = static fn (string $v): string => preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        $lines = ['Datum;Art;Leistung;Herkunft;Arbeitskosten €;Hinweis'];
        foreach ($tax['items'] as $item) {
            $lines[] = implode(';', array_map($cell, [
                Day::parse($item['date'])->format('d.m.Y'),
                $kinds[$item['taxKind']],
                $item['title'],
                $item['source']['title'],
                GermanNumber::format($item['amount'], 2),
                $item['check'] ? 'Arbeitsanteil prüfen' : '',
            ]));
        }
        foreach ($tax['totals'] as $kind => $total) {
            $lines[] = implode(';', ['', $kinds[$kind], 'Summe', '', GermanNumber::format($total['amount'], 2), 'Steuerermäßigung ' . GermanNumber::format($total['reduction'], 2) . ' €']);
        }
        return ["\u{FEFF}" . implode("\r\n", $lines) . "\r\n", "wohnpilot-35a-$year.csv"];
    }

    /** @return list<array<string, mixed>> per meter: the year, the year before, and a cost from the tariff when fully read */
    private function consumption(string $objectId, int $year): array
    {
        $result = [];
        foreach ($this->meters->metersById($objectId) as $meter) {
            $c = new Consumption($meter);
            $span = static function (int $y) use ($c): array {
                $from = sprintf('%04d-01-01', $y);
                $to = sprintf('%04d-01-01', $y + 1);
                $days = Day::between($from, $to);
                $covered = $c->coveredDays($from, $to);
                return ['amount' => round($c->between($from, $to), 3), 'coveredDays' => $covered, 'days' => $days, 'complete' => $covered === $days];
            };
            $now = $span($year);
            $before = $span($year - 1);
            $tariff = $meter->tariff;
            $cost = $now['complete'] && $tariff?->energyPriceCt !== null
                ? round($now['amount'] * $tariff->energyPriceCt / 100 + ($tariff->basePriceEurMonth ?? 0.0) * 12, 2)
                : null;
            $result[] = [
                'id' => $meter->id,
                'medium' => $meter->medium,
                'name' => $meter->name,
                'unit' => $meter->unit,
                'year' => $now,
                'previous' => $before,
                'change' => $now['complete'] && $before['complete'] && $before['amount'] > 0 ? round(($now['amount'] - $before['amount']) / $before['amount'] * 100, 1) : null,
                'cost' => $cost,
            ];
        }
        return $result;
    }

    /** Rent month by month from the lease's history, contracts at their current price for the months they ran */
    private function housing(int $year): array
    {
        $lease = $this->lease->lease();
        $cold = 0.0;
        $utilities = 0.0;
        $months = 0;
        if ($lease !== null) {
            for ($m = 1; $m <= 12; $m++) {
                $first = sprintf('%04d-%02d-01', $year, $m);
                if ($lease->start !== null && $lease->start > Day::lastOfMonth(Day::month($first))) {
                    continue;
                }
                $rent = $lease->rentOn($first);
                $cold += $rent['cold'] ?? 0.0;
                $utilities += $rent['utilities'] ?? 0.0;
                $months++;
            }
        }

        $contracts = [];
        foreach ($this->notes->byType('vertrag') as $stored) {
            $contract = Contract::fromNote(NoteDocument::parse($stored->content));
            $monthly = $contract->monthlyCost();
            if ($monthly === null) {
                continue;
            }
            $term = $contract->term($this->clock->today());
            $end = in_array($term->state, [ContractTerm::CANCELLED, ContractTerm::EXPIRES, ContractTerm::ENDED], true) ? $term->endsOn : null;
            $active = 0;
            for ($m = 1; $m <= 12; $m++) {
                $first = sprintf('%04d-%02d-01', $year, $m);
                $last = Day::lastOfMonth(Day::month($first));
                if (($contract->start === null || $contract->start <= $last) && ($end === null || $end >= $first)) {
                    $active++;
                }
            }
            if ($active > 0) {
                $contracts[] = ['id' => $contract->id, 'name' => $contract->name !== '' ? $contract->name : $contract->provider, 'category' => $contract->category, 'monthly' => $monthly, 'months' => $active, 'total' => round($monthly * $active, 2)];
            }
        }
        usort($contracts, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $contractsTotal = round(array_sum(array_column($contracts, 'total')), 2);

        return [
            'rent' => ['months' => $months, 'cold' => round($cold, 2), 'utilities' => round($utilities, 2)],
            'contracts' => $contracts,
            'contractsTotal' => $contractsTotal,
            'total' => round($cold + $utilities + $contractsTotal, 2),
        ];
    }

    /** Statements for periods that end in the year (the landlord's ones carry the heating) */
    private function statements(int $year): array
    {
        $list = [];
        foreach ($this->utilities->all() as $s) {
            if ((int) substr($s->to, 0, 4) === $year) {
                $list[] = ['id' => $s->id, 'title' => $s->title(), 'kind' => $s->kind, 'total' => $s->total(), 'balance' => $s->balance(), 'billedIn' => $s->billedIn];
            }
        }
        return $list;
    }

    /** Every done row of the year, oldest first */
    private function maintenance(string $objectId, int $year): array
    {
        $done = [];
        foreach ($this->notes->byType('wartung', $objectId) as $stored) {
            $note = NoteDocument::parse($stored->content);
            $fm = $note->frontmatter();
            foreach ($note->body()->table()?->rows() ?? [] as $row) {
                $date = trim($row['Datum'] ?? '');
                if (Day::isValid($date) && (int) substr($date, 0, 4) === $year) {
                    $done[] = [
                        'id' => (string) $fm->get('id', ''),
                        'title' => (string) $fm->get('titel', $fm->get('id', '')),
                        'date' => $date,
                        'by' => trim($row['Erledigt von'] ?? ''),
                        'note' => trim($row['Notiz'] ?? ''),
                    ];
                }
            }
        }
        usort($done, static fn (array $a, array $b): int => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);
        return $done;
    }

    private function fixedDefects(int $year): array
    {
        $fixed = [];
        foreach ($this->defects->defects() as $d) {
            $on = $d->fixedOn();
            if ($on !== null && (int) substr($on, 0, 4) === $year) {
                $fixed[] = ['id' => $d->id, 'title' => $d->title, 'fixedOn' => $on, 'responsible' => $d->responsible, 'cost' => $d->cost];
            }
        }
        usort($fixed, static fn (array $a, array $b): int => $a['fixedOn'] <=> $b['fixedOn']);
        return $fixed;
    }

    /**
     * § 35a items of the year with totals and the possible reduction.
     *
     * @return array{items: list<array<string, mixed>>, totals: array<string, array{amount: float, reduction: float, cap: float}>, reduction: float}
     */
    private function tax(int $year): array
    {
        $items = [];
        foreach ($this->utilities->all() as $s) {
            if ($s->received === null || (int) substr($s->received, 0, 4) !== $year) {
                continue;
            }
            foreach ($s->taxShares() as $share) {
                $items[] = ['date' => $s->received, 'taxKind' => $share['taxKind'], 'title' => $share['kind'], 'amount' => $share['amount'], 'check' => false, 'source' => ['type' => 'nebenkosten', 'id' => $s->id, 'title' => $s->title()]];
            }
        }

        // Minor repairs you paid yourself: tradesmen's work; only the labour share counts
        $repairs = $this->defects->minorRepairs($year);
        $labour = [];
        foreach ($this->notes->byType('mangel') as $stored) {
            $fm = NoteDocument::parse($stored->content)->frontmatter();
            if (is_numeric($fm->get('arbeitskosten_eur'))) {
                $labour[(string) $fm->get('id')] = (float) $fm->get('arbeitskosten_eur');
            }
        }
        foreach ($repairs['cases'] as $case) {
            if ($case['overLimit']) {
                continue;
            }
            $known = $labour[$case['id']] ?? null;
            $items[] = ['date' => $case['date'], 'taxKind' => 'handwerker', 'title' => $case['title'], 'amount' => round($known ?? $case['cost'], 2), 'check' => $known === null, 'source' => ['type' => 'mangel', 'id' => $case['id'], 'title' => 'Kleinreparatur']];
        }
        // By date; on one day in the order of the statement
        usort($items, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $totals = [];
        $reduction = 0.0;
        foreach (self::TAX_CAPS as $kind => $cap) {
            $amount = round(array_sum(array_column(array_filter($items, static fn (array $i): bool => $i['taxKind'] === $kind), 'amount')), 2);
            if ($amount <= 0) {
                continue;
            }
            $r = round(min($amount * self::TAX_RATE, $cap), 2);
            $totals[$kind] = ['amount' => $amount, 'reduction' => $r, 'cap' => $cap];
            $reduction += $r;
        }
        return ['items' => $items, 'totals' => $totals, 'reduction' => round($reduction, 2)];
    }
}
