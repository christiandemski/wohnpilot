<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A utility statement (`typ: nebenkosten`): the landlord's statement of
 * operating costs or the metering service's heating statement. Sum, result,
 * deadlines and comparisons are derived, never stored.
 *
 * Deadlines (§ 556 Abs. 3 BGB): the landlord must deliver within twelve
 * months after the period ends, else he cannot ask for more; you can object
 * until the end of the twelfth month after receipt. A back payment is due
 * after a reasonable time to check it, taken here as 30 days.
 */
final readonly class UtilityStatement
{
    public const COLUMNS = ['Kostenart', 'Betrag €', '§ 35a', 'davon § 35a €'];
    public const KINDS = ['betriebskosten', 'heizkosten'];
    public const TAX_KINDS = ['haushaltsnah', 'handwerker'];
    public const DELIVERY_MONTHS = 12;
    public const OBJECTION_MONTHS = 12;
    public const PAYMENT_DAYS = 30;
    /** Lead time before the objection deadline */
    public const OBJECTION_LEAD_DAYS = 30;

    /** Consumption keys of `verbrauch` => [meter medium, unit] */
    public const CONSUMPTION = [
        'wasser_m3' => ['wasser', 'm³'],
        'warmwasser_m3' => ['warmwasser', 'm³'],
        'waerme_kwh' => ['waerme', 'kWh'],
        'gas_kwh' => ['gas', 'kWh'],
        'gas_m3' => ['gas', 'm³'],
        'strom_kwh' => ['strom', 'kWh'],
    ];

    /**
     * @param list<array{kind: string, amount: float, taxKind: ?string, taxAmount: ?float}> $positions in note order
     * @param array<string, float> $consumption
     */
    public function __construct(
        public string $id,
        public string $object,
        public string $kind,
        public string $from,
        public string $to,
        public ?string $received,
        public ?float $prepayments,
        public ?float $newPrepayment,
        public ?string $newPrepaymentFrom,
        public ?string $paidOn,
        public ?string $billedIn,
        public array $consumption,
        public array $positions,
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $date = static fn (string $key): ?string => is_string($v = $fm->get($key)) && Day::isValid($v) ? $v : null;
        $number = static fn (string $key): ?float => is_numeric($v = $fm->get($key)) ? (float) $v : null;

        $positions = [];
        foreach ($note->body()->table()?->rows() ?? [] as $row) {
            $name = trim($row['Kostenart'] ?? '');
            $amount = Reading::parseNumber($row['Betrag €'] ?? '');
            if ($name === '' || $amount === null) {
                continue;
            }
            $taxKind = trim($row['§ 35a'] ?? '');
            $positions[] = [
                'kind' => $name,
                'amount' => $amount,
                'taxKind' => in_array($taxKind, self::TAX_KINDS, true) ? $taxKind : null,
                'taxAmount' => Reading::parseNumber($row['davon § 35a €'] ?? ''),
            ];
        }
        $consumption = [];
        foreach ((array) ($fm->get('verbrauch') ?? []) as $key => $value) {
            if (isset(self::CONSUMPTION[$key]) && is_numeric($value)) {
                $consumption[$key] = (float) $value;
            }
        }
        $billedIn = $fm->get('abrechnung');

        return new self(
            id: (string) $fm->get('id', ''),
            object: (string) $fm->get('objekt', ''),
            kind: in_array($fm->get('art'), self::KINDS, true) ? (string) $fm->get('art') : 'betriebskosten',
            from: $date('von') ?? '',
            to: $date('bis') ?? '',
            received: $date('zugang'),
            prepayments: $number('vorauszahlungen_eur'),
            newPrepayment: $number('neue_vorauszahlung_eur'),
            newPrepaymentFrom: $date('neue_vorauszahlung_ab'),
            paidOn: $date('bezahlt'),
            billedIn: is_string($billedIn) && $billedIn !== '' ? $billedIn : null,
            consumption: $consumption,
            positions: $positions,
        );
    }

    /** "Betriebskosten 2025", "Heizkosten 2024/25" for a period over two years */
    public function title(): string
    {
        $name = $this->kind === 'heizkosten' ? 'Heizkosten' : 'Betriebskosten';
        $start = substr($this->from, 0, 4);
        $end = substr($this->to, 0, 4);
        return $name . ' ' . ($start === $end || $start === '' ? $end : $start . '/' . substr($end, 2));
    }

    public function total(): float
    {
        return round(array_sum(array_column($this->positions, 'amount')), 2);
    }

    /** Positive: back payment, negative: credit; null without prepayments (heating statement billed by the landlord) */
    public function balance(): ?float
    {
        return $this->prepayments === null ? null : round($this->total() - $this->prepayments, 2);
    }

    /** Days in the period, both ends included */
    public function days(): int
    {
        return $this->from === '' || $this->to === '' ? 0 : Day::between($this->from, $this->to) + 1;
    }

    /** Amount scaled to a year, so a move-in month compares with a full year */
    public function perYear(float $amount): float
    {
        $days = $this->days();
        return $days <= 0 ? $amount : round($amount * 365 / $days, 2);
    }

    public function deliveryDeadline(): string
    {
        return Day::lastOfMonth(Day::addMonths(Day::month($this->to), self::DELIVERY_MONTHS));
    }

    /** Received too late: the landlord cannot ask for a back payment (§ 556 Abs. 3 S. 3 BGB) */
    public function deliveredLate(): bool
    {
        return $this->received !== null && $this->to !== '' && $this->received > $this->deliveryDeadline();
    }

    /** Objections go against the landlord's statement; a heating statement billed in it has none of its own */
    public function objectionDeadline(): ?string
    {
        return $this->received === null || $this->billedIn !== null ? null : Day::lastOfMonth(Day::addMonths(Day::month($this->received), self::OBJECTION_MONTHS));
    }

    /** Due day of a back payment still to pay, else null */
    public function paymentDue(): ?string
    {
        $balance = $this->balance();
        if ($balance === null || $balance <= 0 || $this->paidOn !== null || $this->received === null || $this->deliveredLate()) {
            return null;
        }
        return Day::addDays($this->received, self::PAYMENT_DAYS);
    }

    /**
     * Each kind of cost against the previous statement of the same kind, per year.
     *
     * @return list<array{kind: string, amount: float, perYear: float, previousPerYear: ?float, change: ?float}>
     */
    public function compareWith(?self $previous): array
    {
        $before = [];
        foreach ($previous?->positions ?? [] as $p) {
            $key = self::key($p['kind']);
            $before[$key] = ($before[$key] ?? 0.0) + $previous->perYear($p['amount']);
        }
        $rows = [];
        foreach ($this->positions as $p) {
            $perYear = $this->perYear($p['amount']);
            $old = $before[self::key($p['kind'])] ?? null;
            $rows[] = [
                'kind' => $p['kind'],
                'amount' => $p['amount'],
                'perYear' => $perYear,
                'previousPerYear' => $old === null ? null : round($old, 2),
                'change' => $old === null || $old == 0.0 ? null : round(($perYear - $old) / $old * 100, 1),
            ];
        }
        return $rows;
    }

    /**
     * Billed consumption against your own readings over the same period.
     *
     * @param list<Meter> $meters meters of the object
     * @return list<array{key: string, unit: string, billed: float, meterId: ?string, measured: ?float, coveredDays: int, days: int, difference: ?float}>
     */
    public function checkAgainst(array $meters): array
    {
        $checks = [];
        foreach ($this->consumption as $key => $billed) {
            [$medium, $unit] = self::CONSUMPTION[$key];
            $meter = null;
            foreach ($meters as $candidate) {
                if ($candidate->medium === $medium && mb_strtolower($candidate->unit) === mb_strtolower($unit)) {
                    $meter = $candidate;
                    break;
                }
            }
            $days = $this->days();
            $covered = 0;
            $measured = null;
            if ($meter !== null && $days > 0) {
                $consumption = new Consumption($meter);
                $end = Day::addDays($this->to, 1);
                $covered = $consumption->coveredDays($this->from, $end);
                // Only a fully read period says something
                $measured = $covered === $days ? round($consumption->between($this->from, $end), 3) : null;
            }
            $checks[] = [
                'key' => $key,
                'unit' => $unit,
                'billed' => $billed,
                'meterId' => $meter?->id,
                'measured' => $measured,
                'coveredDays' => $covered,
                'days' => $days,
                'difference' => $measured === null || $measured == 0.0 ? null : round(($billed - $measured) / $measured * 100, 1),
            ];
        }
        return $checks;
    }

    /**
     * § 35a amounts of this statement.
     *
     * @return list<array{kind: string, taxKind: string, amount: float}>
     */
    public function taxShares(): array
    {
        $shares = [];
        foreach ($this->positions as $p) {
            if ($p['taxKind'] !== null) {
                $shares[] = ['kind' => $p['kind'], 'taxKind' => $p['taxKind'], 'amount' => $p['taxAmount'] ?? $p['amount']];
            }
        }
        return $shares;
    }

    /** "Heiz- und Warmwasserkosten" and "heiz- und warmwasserkosten " are one kind */
    private static function key(string $kind): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $kind)));
    }
}
