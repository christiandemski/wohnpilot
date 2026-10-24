<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Minor repairs (Kleinreparaturen) the tenant pays under the lease: each
 * case up to a limit, all cases of a calendar year up to a yearly cap. A
 * case above the limit is not a minor repair at all – the landlord pays it
 * in full – so it does not count towards the year.
 */
final class MinorRepairs
{
    /**
     * @param list<Defect> $defects
     * @return array{year: int, cases: list<array{id: string, title: string, date: string, cost: float, overLimit: bool}>,
     *   total: float, perCase: ?float, perYear: ?float, left: ?float, overYear: bool}
     */
    public static function forYear(array $defects, int $year, ?float $perCase, ?float $perYear): array
    {
        $cases = [];
        $total = 0.0;
        foreach ($defects as $defect) {
            if (!$defect->minorRepair || $defect->cost === null) {
                continue;
            }
            $date = $defect->fixedOn() ?? $defect->discovered();
            if ($date === null || (int) substr($date, 0, 4) !== $year) {
                continue;
            }
            $overLimit = $perCase !== null && $defect->cost > $perCase;
            $cases[] = ['id' => $defect->id, 'title' => $defect->title, 'date' => $date, 'cost' => $defect->cost, 'overLimit' => $overLimit];
            if (!$overLimit) {
                $total += $defect->cost;
            }
        }
        usort($cases, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        $total = round($total, 2);
        return [
            'year' => $year,
            'cases' => $cases,
            'total' => $total,
            'perCase' => $perCase,
            'perYear' => $perYear,
            'left' => $perYear === null ? null : round(max(0.0, $perYear - $total), 2),
            'overYear' => $perYear !== null && $total > $perYear,
        ];
    }
}
