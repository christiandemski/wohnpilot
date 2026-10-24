<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * How urgent a date is, in the statuses of the StatusChip: ok, soon or due.
 * A past date is also "due"; its negative daysUntil makes it overdue.
 */
final class Urgency
{
    public const OK = 'ok';
    public const SOON = 'soon';
    public const DUE = 'due';

    /** Lead time for contract deadlines, as for the FristRing (ok above 30 days). */
    public const CONTRACT_LEAD_DAYS = 30;

    public static function of(int $daysUntil, int $leadDays): string
    {
        return match (true) {
            $daysUntil <= 0 => self::DUE,
            $daysUntil <= $leadDays => self::SOON,
            default => self::OK,
        };
    }
}
