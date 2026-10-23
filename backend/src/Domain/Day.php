<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calendar-day helpers on ISO strings ("2026-10-24"), free of time zones and clocks. */
final class Day
{
    public static function parse(string $iso): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $iso) {
            throw new InvalidArgumentException("Not an ISO date: $iso");
        }
        return $date;
    }

    public static function isValid(string $iso): bool
    {
        try {
            self::parse($iso);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function addDays(string $iso, int $days): string
    {
        return self::parse($iso)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Whole days from $from to $to (negative if $to is earlier). */
    public static function between(string $from, string $to): int
    {
        return (int) self::parse($from)->diff(self::parse($to))->format('%r%a');
    }

    /** "2026-10" */
    public static function month(string $iso): string
    {
        return substr($iso, 0, 7);
    }

    public static function addMonths(string $month, int $months): string
    {
        return self::parse($month . '-01')->modify(($months >= 0 ? '+' : '') . $months . ' months')->format('Y-m');
    }

    /** Day $day of the month, clamped to the month's length (31 → 30.11.). */
    public static function dayOfMonth(string $month, int $day): string
    {
        $first = self::parse($month . '-01');
        $day = max(1, min($day, (int) $first->format('t')));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }
}
