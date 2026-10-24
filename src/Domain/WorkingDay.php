<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Working days for notice deadlines in tenancy law: Monday to Saturday without
 * nationwide public holidays. Counting Saturday and leaving out regional holidays
 * errs on the early side, so a deadline from here is never too late.
 */
final class WorkingDay
{
    public static function isWorkingDay(string $iso): bool
    {
        return Day::weekday($iso) !== 7 && !self::isNationwideHoliday($iso);
    }

    /** The $n-th working day of a month ("2026-10" → 3rd working day). */
    public static function nth(string $month, int $n): string
    {
        $day = $month . '-01';
        $count = 0;
        while (true) {
            if (self::isWorkingDay($day) && ++$count === $n) {
                return $day;
            }
            $day = Day::addDays($day, 1);
        }
    }

    public static function isNationwideHoliday(string $iso): bool
    {
        if (in_array(substr($iso, 5), ['01-01', '05-01', '10-03', '12-25', '12-26'], true)) {
            return true;
        }
        $easter = self::easterSunday((int) substr($iso, 0, 4));
        $offset = Day::between($easter, $iso);
        // Good Friday, Easter Monday, Ascension, Whit Monday
        return in_array($offset, [-2, 1, 39, 50], true);
    }

    /** Gregorian Easter Sunday (anonymous Gregorian algorithm). */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
