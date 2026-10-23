<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Consumption derived from a meter's readings. Nothing here is stored.
 *
 * Between two readings the consumption is spread evenly over the days in
 * between; months and years are sums of those days. A meter change (two
 * rows "Zählerwechsel" on one day: old end value, then new start value)
 * contributes no consumption.
 */
final class Consumption
{
    /** @var list<array{from: string, to: string, days: int, amount: float}> */
    private array $intervals = [];

    /** @var array<string, float> day => amount on that day */
    private array $daily = [];

    public function __construct(private readonly Meter $meter)
    {
        $readings = $meter->readings;
        for ($i = 1, $count = count($readings); $i < $count; $i++) {
            $previous = $readings[$i - 1];
            $current = $readings[$i];
            if ($current->isMeterChange() && $previous->isMeterChange() && $current->date === $previous->date) {
                continue;
            }
            $days = Day::between($previous->date, $current->date);
            $amount = $current->value - $previous->value;
            if ($days <= 0) {
                // Two readings on one day: the difference belongs to that day.
                $this->daily[$current->date] = ($this->daily[$current->date] ?? 0.0) + $amount;
                continue;
            }
            $this->intervals[] = ['from' => $previous->date, 'to' => $current->date, 'days' => $days, 'amount' => $amount];
            $perDay = $amount / $days;
            for ($d = 0; $d < $days; $d++) {
                $day = Day::addDays($previous->date, $d);
                $this->daily[$day] = ($this->daily[$day] ?? 0.0) + $perDay;
            }
        }
    }

    /** @return list<array{from: string, to: string, days: int, amount: float}> oldest first */
    public function intervals(): array
    {
        return $this->intervals;
    }

    /** Consumption from day $from (inclusive) to day $to (exclusive), only over covered days. */
    public function between(string $from, string $to): float
    {
        $sum = 0.0;
        foreach ($this->daily as $day => $amount) {
            if ($day >= $from && $day < $to) {
                $sum += $amount;
            }
        }
        return $sum;
    }

    /** Number of days in [$from, $to) that lie between two readings. */
    public function coveredDays(string $from, string $to): int
    {
        $count = 0;
        foreach ($this->daily as $day => $_) {
            if ($day >= $from && $day < $to) {
                $count++;
            }
        }
        return $count;
    }

    public function isCovered(string $day): bool
    {
        return isset($this->daily[$day]);
    }

    /**
     * Average consumption per day over the last $windowDays days of known
     * consumption up to $asOf. Null without any interval.
     */
    public function typicalDailyRate(string $asOf, int $windowDays = 365): ?float
    {
        $from = Day::addDays($asOf, -$windowDays);
        $days = $this->coveredDays($from, $asOf);
        if ($days === 0) {
            return null;
        }
        return $this->between($from, $asOf) / $days;
    }

    /**
     * Consumption per calendar month for the $count months ending with the
     * month of $asOf, each with last year's value for the same month.
     * A value is null when no day of that month is covered.
     *
     * @return list<array{month: string, amount: ?float, coveredDays: int, days: int, previousYear: ?float}>
     */
    public function monthly(string $asOf, int $count = 12): array
    {
        $last = Day::month($asOf);
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $month = Day::addMonths($last, -$i);
            $start = $month . '-01';
            $end = Day::addMonths($month, 1) . '-01';
            $covered = $this->coveredDays($start, $end);
            $previousStart = Day::addMonths($month, -12) . '-01';
            $previousEnd = Day::addMonths($month, -11) . '-01';
            $previousCovered = $this->coveredDays($previousStart, $previousEnd);
            $months[] = [
                'month' => $month,
                'amount' => $covered > 0 ? $this->between($start, $end) : null,
                'coveredDays' => $covered,
                'days' => Day::between($start, $end),
                'previousYear' => $previousCovered > 0 ? $this->between($previousStart, $previousEnd) : null,
            ];
        }
        return $months;
    }

    /** @return list<array{year: int, amount: float, coveredDays: int, days: int}> years with any data, oldest first */
    public function yearly(): array
    {
        $years = [];
        foreach ($this->daily as $day => $amount) {
            $year = (int) substr($day, 0, 4);
            $years[$year] ??= ['year' => $year, 'amount' => 0.0, 'coveredDays' => 0, 'days' => Day::between("$year-01-01", ($year + 1) . '-01-01')];
            $years[$year]['amount'] += $amount;
            $years[$year]['coveredDays']++;
        }
        ksort($years);
        return array_values($years);
    }

    /**
     * Expected consumption for the period [$start, $end): measured days count
     * as measured; every other day takes last year's value for that day if
     * known, otherwise the typical daily rate as of $asOf.
     *
     * @return array{amount: float, measured: float, estimatedDays: int}|null null without any data
     */
    public function projection(string $start, string $end, string $asOf): ?array
    {
        $rate = $this->typicalDailyRate($asOf);
        if ($rate === null) {
            return null;
        }
        $measured = 0.0;
        $estimated = 0.0;
        $estimatedDays = 0;
        for ($day = $start; $day < $end; $day = Day::addDays($day, 1)) {
            if ($day < $asOf && isset($this->daily[$day])) {
                $measured += $this->daily[$day];
                continue;
            }
            $lastYear = Day::parse($day)->modify('-1 year')->format('Y-m-d');
            $estimated += $this->daily[$lastYear] ?? $rate;
            $estimatedDays++;
        }
        return ['amount' => $measured + $estimated, 'measured' => $measured, 'estimatedDays' => $estimatedDays];
    }

    public function meter(): Meter
    {
        return $this->meter;
    }
}
