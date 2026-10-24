<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/** A notice period of some weeks or months (`kuendigungsfrist_wochen` / `kuendigungsfrist_monate`). */
final readonly class NoticePeriod
{
    private function __construct(public int $amount, public string $unit)
    {
    }

    public static function weeks(int $weeks): self
    {
        return new self(max(0, $weeks), 'wochen');
    }

    public static function months(int $months): self
    {
        return new self(max(0, $months), 'monate');
    }

    /** Months win if a note has both; null if it has neither. */
    public static function fromFields(mixed $weeks, mixed $months): ?self
    {
        if (is_numeric($months)) {
            return self::months((int) $months);
        }
        if (is_numeric($weeks)) {
            return self::weeks((int) $weeks);
        }
        return null;
    }

    /**
     * Last day the notice must arrive so that the contract ends on $end:
     * the full period lies between that day and the end (30.11., 4 weeks → 02.11.;
     * 31.12., 3 months → 30.09.). Missing month days are clamped, which errs on the early side.
     */
    public function latestNoticeFor(string $end): string
    {
        $after = Day::addDays($end, 1);
        $start = $this->unit === 'wochen'
            ? Day::addDays($after, -7 * $this->amount)
            : Day::addMonthsToDate($after, -$this->amount);
        return Day::addDays($start, -1);
    }

    /** Earliest end if the notice arrives on $day, for contracts that can end on any day. */
    public function endIfGivenOn(string $day): string
    {
        return $this->unit === 'wochen'
            ? Day::addDays($day, 7 * $this->amount)
            : Day::addMonthsToDate($day, $this->amount);
    }
}
