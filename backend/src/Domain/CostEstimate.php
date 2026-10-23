<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Expected cost of a billing year against the monthly instalments, with the
 * instalment that would end the year at zero.
 */
final class CostEstimate
{
    /**
     * @return array{
     *   periodStart: string, periodEnd: string, projectedAmount: float,
     *   cost: float, paid: float, balance: float, suggestedInstalment: ?int
     * }|null null without tariff or data. balance > 0 means you pay extra.
     */
    public static function forYear(Consumption $consumption, string $asOf, string $billingStart = '01-01'): ?array
    {
        $tariff = $consumption->meter()->tariff;
        if ($tariff === null || $tariff->energyPriceCt === null) {
            return null;
        }
        [$start, $end] = self::period($asOf, $billingStart);
        $projection = $consumption->projection($start, $end, $asOf);
        if ($projection === null) {
            return null;
        }

        $cost = $projection['amount'] * $tariff->energyPriceCt / 100 + 12 * ($tariff->basePriceEurMonth ?? 0.0);
        $paid = 12 * ($tariff->instalmentEur ?? 0.0);

        return [
            'periodStart' => $start,
            'periodEnd' => $end,
            'projectedAmount' => $projection['amount'],
            'cost' => $cost,
            'paid' => $paid,
            'balance' => $cost - $paid,
            'suggestedInstalment' => $tariff->instalmentEur === null ? null : (int) ceil($cost / 12),
        ];
    }

    /** @return array{string, string} the billing year containing $asOf, end exclusive */
    public static function period(string $asOf, string $billingStart = '01-01'): array
    {
        $year = (int) substr($asOf, 0, 4);
        $start = sprintf('%04d-%s', $year, $billingStart);
        if ($start > $asOf) {
            $start = sprintf('%04d-%s', $year - 1, $billingStart);
        }
        $end = sprintf('%04d-%s', (int) substr($start, 0, 4) + 1, $billingStart);
        return [$start, $end];
    }
}
