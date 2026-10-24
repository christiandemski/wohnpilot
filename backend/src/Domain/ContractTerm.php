<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/** Where a contract stands on a given day. Only the fields that fit the state are set. */
final readonly class ContractTerm
{
    /** Renews unless cancelled: noticeBy is the next deadline. */
    public const RUNNING = 'running';
    /** Fixed term without renewal: ends on endsOn by itself. */
    public const EXPIRES = 'expires';
    /** Cancelled, ends on endsOn. */
    public const CANCELLED = 'cancelled';
    public const ENDED = 'ended';
    /** No fixed periods (no start or term): can be cancelled any time with the notice period. */
    public const OPEN = 'open';

    public function __construct(
        public string $state,
        public ?string $periodStart = null,
        /** Start of the countdown to noticeBy: the day after the previous deadline, or the contract start. */
        public ?string $countdownFrom = null,
        public ?string $periodEnd = null,
        public ?string $noticeBy = null,
        public ?int $daysUntilNotice = null,
        public ?string $status = null,
        public ?int $renewsMonths = null,
        public ?string $endsOn = null,
        public ?int $daysUntilEnd = null,
        public ?string $endIfCancelledToday = null,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
