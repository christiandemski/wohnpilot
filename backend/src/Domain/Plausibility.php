<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Checks a new reading against the last one and the usual consumption.
 * Saving stays possible in every case; the result only informs.
 */
final class Plausibility
{
    public const OK = 'ok';
    public const LOWER = 'lower';
    public const HIGH = 'high';
    public const FIRST = 'first';

    /** A reading counts as unusually high above this multiple of the expected consumption. */
    public const HIGH_FACTOR = 2.0;

    /** Below this many expected units a jump is not judged (e.g. two readings a day apart). */
    private const MIN_EXPECTED = 1.0;

    /**
     * @return array{result: string, delta: ?float, days: ?int, perDay: ?float, expected: ?float, threshold: ?float}
     */
    public static function check(Consumption $consumption, float $value, string $date): array
    {
        $last = $consumption->meter()->latest();
        if ($last === null) {
            return ['result' => self::FIRST, 'delta' => null, 'days' => null, 'perDay' => null, 'expected' => null, 'threshold' => null];
        }

        $delta = $value - $last->value;
        $days = Day::between($last->date, $date);
        $perDay = $days > 0 ? $delta / $days : null;
        $rate = $consumption->typicalDailyRate($last->date);
        $expected = $rate !== null && $days > 0 ? $rate * $days : null;
        $threshold = $expected !== null ? max($expected * self::HIGH_FACTOR, self::MIN_EXPECTED) : null;

        $result = match (true) {
            $delta < 0 => self::LOWER,
            $threshold !== null && $delta > $threshold => self::HIGH,
            default => self::OK,
        };

        return ['result' => $result, 'delta' => $delta, 'days' => $days, 'perDay' => $perDay, 'expected' => $expected, 'threshold' => $threshold];
    }
}
