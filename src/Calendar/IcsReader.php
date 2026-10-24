<?php

declare(strict_types=1);

namespace Wohnpilot\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Wohnpilot\Domain\Day;

/**
 * Reads the events of an iCalendar file (RFC 5545) as calendar days, enough
 * for municipal waste calendars: single events and RRULE with FREQ
 * DAILY/WEEKLY/MONTHLY/YEARLY, INTERVAL, COUNT, UNTIL, plus EXDATE and
 * RECURRENCE-ID overrides. Times only decide the day (in Europe/Berlin).
 */
final class IcsReader
{
    /** Never expand more than this, whatever a rule says. */
    private const MAX_OCCURRENCES = 1000;

    /**
     * @return list<array{date: string, time: ?string, summary: string, uid: string}> sorted by date,
     *   only occurrences from $from to $to (inclusive)
     */
    public static function occurrences(string $ics, string $from, string $to): array
    {
        $events = self::events($ics);
        // Moved single occurrences replace the one of the series on their original day.
        $overridden = [];
        foreach ($events as $event) {
            if (isset($event['RECURRENCE-ID'])) {
                $overridden[($event['UID']['value'] ?? '') . '|' . self::day($event['RECURRENCE-ID'])['date']] = true;
            }
        }

        $result = [];
        foreach ($events as $event) {
            if (!isset($event['DTSTART'])) {
                continue;
            }
            $start = self::day($event['DTSTART']);
            $uid = $event['UID']['value'] ?? '';
            $excluded = [];
            foreach ($event['EXDATE'] ?? [] as $exdate) {
                foreach (explode(',', $exdate['value']) as $one) {
                    $excluded[self::day(['value' => $one] + $exdate)['date']] = true;
                }
            }
            $dates = isset($event['RRULE']) && !isset($event['RECURRENCE-ID'])
                ? self::expand($start['date'], $event['RRULE']['value'], $to)
                : [$start['date']];
            foreach ($dates as $date) {
                if ($date < $from || $date > $to || isset($excluded[$date])) {
                    continue;
                }
                if (!isset($event['RECURRENCE-ID']) && isset($overridden["$uid|$date"])) {
                    continue;
                }
                $result[] = ['date' => $date, 'time' => $start['time'], 'summary' => $event['SUMMARY']['value'] ?? '', 'uid' => $uid];
            }
        }
        usort($result, static fn (array $a, array $b): int => [$a['date'], $a['time'] ?? ''] <=> [$b['date'], $b['time'] ?? '']);
        return $result;
    }

    /**
     * @return list<array<string, mixed>> per event: property name => ['value' => …, 'params' => […]];
     *   EXDATE as a list, since it may repeat
     */
    private static function events(string $ics): array
    {
        // Unfold: a line break followed by a space or tab continues the line.
        $text = preg_replace("/\r?\n[ \t]/", '', $ics) ?? '';
        $events = [];
        $current = null;
        $depth = 0;
        foreach (preg_split("/\r?\n/", $text) ?: [] as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                $depth = 0;
                continue;
            }
            if ($current === null) {
                continue;
            }
            if ($line === 'END:VEVENT') {
                $events[] = $current;
                $current = null;
                continue;
            }
            // Skip nested components like VALARM.
            if (str_starts_with($line, 'BEGIN:')) {
                $depth++;
                continue;
            }
            if (str_starts_with($line, 'END:')) {
                $depth--;
                continue;
            }
            if ($depth > 0 || !preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) {
                continue;
            }
            $params = [];
            foreach (array_filter(explode(';', $m[2])) as $param) {
                [$key, $value] = array_pad(explode('=', $param, 2), 2, '');
                $params[strtoupper($key)] = trim($value, '"');
            }
            $property = ['value' => $m[1] === 'SUMMARY' || $m[1] === 'DESCRIPTION' ? self::unescape($m[3]) : $m[3], 'params' => $params];
            if ($m[1] === 'EXDATE') {
                $current['EXDATE'][] = $property;
            } else {
                $current[$m[1]] ??= $property;
            }
        }
        return $events;
    }

    /** @return array{date: string, time: ?string} a DATE or DATE-TIME as local day in Europe/Berlin */
    private static function day(array $property): array
    {
        $value = trim($property['value']);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return ['date' => "$m[1]-$m[2]-$m[3]", 'time' => null];
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m)) {
            return ['date' => '0000-00-00', 'time' => null];
        }
        $local = new DateTimeZone('Europe/Berlin');
        $zone = $m[7] === 'Z' ? new DateTimeZone('UTC') : self::zone($property['params']['TZID'] ?? null) ?? $local;
        $moment = (new DateTimeImmutable("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", $zone))->setTimezone($local);
        return ['date' => $moment->format('Y-m-d'), 'time' => $moment->format('H:i')];
    }

    private static function zone(?string $tzid): ?DateTimeZone
    {
        try {
            return $tzid === null ? null : new DateTimeZone($tzid);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return list<string> days of a series from $start, up to $until at most */
    private static function expand(string $start, string $rule, string $until): array
    {
        $parts = [];
        foreach (explode(';', $rule) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[strtoupper($key)] = $value;
        }
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? max(0, (int) $parts['COUNT']) : null;
        $end = $until;
        if (isset($parts['UNTIL']) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $parts['UNTIL'], $m)) {
            $end = min($end, "$m[1]-$m[2]-$m[3]");
        }
        $step = match ($parts['FREQ'] ?? '') {
            'DAILY' => static fn (string $d, int $n): string => Day::addDays($d, $n * $interval),
            'WEEKLY' => static fn (string $d, int $n): string => Day::addDays($d, 7 * $n * $interval),
            'MONTHLY' => static fn (string $d, int $n): string => Day::addMonthsToDate($d, $n * $interval),
            'YEARLY' => static fn (string $d, int $n): string => Day::addMonthsToDate($d, 12 * $n * $interval),
            default => null,
        };
        if ($step === null) {
            return [$start];
        }
        $dates = [];
        for ($n = 0; $n < self::MAX_OCCURRENCES && ($count === null || $n < $count); $n++) {
            $date = $step($start, $n);
            if ($date > $end) {
                break;
            }
            $dates[] = $date;
        }
        return $dates;
    }

    private static function unescape(string $text): string
    {
        return strtr($text, ['\\\\' => '\\', '\\;' => ';', '\\,' => ',', '\\n' => "\n", '\\N' => "\n"]);
    }
}
