<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * When the next voluntary reading of a meter is suggested.
 *
 * Readings are suggested on the reading day of the household (`ablesetag`)
 * in every month, quarter or year. A reading taken up to EARLY_DAYS before a
 * suggested date counts for that date. The rhythm is your own choice, so it
 * never warns: from the date on it is just "open". Readings the supplier asks
 * for are deadlines (`typ: frist`) linked to the meter.
 */
final class ReadingSchedule
{
    public const EARLY_DAYS = 5;

    /** Next suggested date still ahead. */
    public const STATUS_OK = 'ok';
    /** Suggested date reached: reading possible. */
    public const STATUS_OPEN = 'open';

    private const MONTHS_PER_INTERVAL = [
        'monatlich' => 1,
        'quartalsweise' => 3,
        'halbjaehrlich' => 6,
        'jaehrlich' => 12,
    ];

    /** @return array{dueDate: string, daysUntil: int, status: string} */
    public static function next(Meter $meter, int $readingDay, string $today): array
    {
        $last = $meter->latest();
        if ($last === null) {
            return ['dueDate' => $today, 'daysUntil' => 0, 'status' => self::STATUS_OPEN];
        }

        $step = self::MONTHS_PER_INTERVAL[$meter->interval] ?? 1;
        $after = Day::addDays($last->date, self::EARLY_DAYS);
        // Scheduled months are aligned to January: monthly every month, quarterly Jan/Apr/Jul/Oct, …
        $month = Day::month($last->date);
        $monthIndex = (int) substr($month, 5, 2) - 1;
        $month = Day::addMonths($month, -($monthIndex % $step));
        $due = Day::dayOfMonth($month, $readingDay);
        while ($due <= $after) {
            $month = Day::addMonths($month, $step);
            $due = Day::dayOfMonth($month, $readingDay);
        }

        $daysUntil = Day::between($today, $due);
        $status = $daysUntil <= 0 ? self::STATUS_OPEN : self::STATUS_OK;

        return ['dueDate' => $due, 'daysUntil' => $daysUntil, 'status' => $status];
    }
}
