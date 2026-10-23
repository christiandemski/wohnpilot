<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * When the next reading of a meter is due.
 *
 * Readings are scheduled on the reading day of the household (`ablesetag`)
 * in every month, quarter or year. A reading taken up to EARLY_DAYS before a
 * scheduled date counts for that date.
 */
final class ReadingSchedule
{
    public const EARLY_DAYS = 5;
    public const SOON_DAYS = 7;

    public const STATUS_OK = 'ok';
    public const STATUS_SOON = 'soon';
    public const STATUS_DUE = 'due';

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
            return ['dueDate' => $today, 'daysUntil' => 0, 'status' => self::STATUS_DUE];
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
        $status = match (true) {
            $daysUntil <= 0 => self::STATUS_DUE,
            $daysUntil <= self::SOON_DAYS => self::STATUS_SOON,
            default => self::STATUS_OK,
        };

        return ['dueDate' => $due, 'daysUntil' => $daysUntil, 'status' => $status];
    }
}
