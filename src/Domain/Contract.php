<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A contract or insurance (`typ: vertrag`) read from its note.
 * Everything with a date – term end, notice deadline, special termination – is derived here.
 */
final readonly class Contract
{
    public const PRICE_COLUMN = 'Gültig ab';

    private const MONTHS_PER_RHYTHM = [
        'monatlich' => 1,
        'quartalsweise' => 3,
        'halbjaehrlich' => 6,
        'jaehrlich' => 12,
    ];

    /** @param list<string> $priceDates `Gültig ab` of the price history, newest first */
    public function __construct(
        public string $id,
        public string $category,
        public string $provider,
        public ?float $cost = null,
        public string $rhythm = 'monatlich',
        public ?string $start = null,
        public ?int $minimumMonths = null,
        public ?int $extensionMonths = null,
        public ?NoticePeriod $notice = null,
        public ?string $cancelledOn = null,
        public ?string $cancelledTo = null,
        public ?int $leadDays = null,
        public array $priceDates = [],
        public string $name = '',
        public string $number = '',
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $date = static fn (string $key): ?string => is_string($v = $fm->get($key)) && Day::isValid($v) ? $v : null;
        $int = static fn (string $key): ?int => is_numeric($v = $fm->get($key)) ? (int) $v : null;

        $priceDates = [];
        foreach ($note->body()->tables() as $table) {
            if (in_array(self::PRICE_COLUMN, $table->headers(), true)) {
                foreach ($table->rows() as $row) {
                    $from = trim($row[self::PRICE_COLUMN]);
                    if (Day::isValid($from)) {
                        $priceDates[] = $from;
                    }
                }
                break;
            }
        }
        rsort($priceDates);

        return new self(
            id: (string) $fm->get('id', ''),
            category: (string) $fm->get('kategorie', ''),
            provider: (string) $fm->get('anbieter', ''),
            cost: is_numeric($fm->get('kosten_eur')) ? (float) $fm->get('kosten_eur') : null,
            rhythm: (string) $fm->get('rhythmus', 'monatlich'),
            start: $date('beginn'),
            minimumMonths: $int('mindestlaufzeit_monate'),
            extensionMonths: $int('verlaengerung_monate'),
            notice: NoticePeriod::fromFields($fm->get('kuendigungsfrist_wochen'), $fm->get('kuendigungsfrist_monate')),
            cancelledOn: $date('gekuendigt_am'),
            cancelledTo: $date('gekuendigt_zum'),
            leadDays: $int('vorlauf_tage'),
            priceDates: $priceDates,
            name: (string) $fm->get('bezeichnung', ''),
            number: (string) $fm->get('vertragsnummer', ''),
        );
    }

    public function term(string $today): ContractTerm
    {
        $lead = $this->leadDays ?? Urgency::CONTRACT_LEAD_DAYS;

        $endsOn = $this->cancelledTo;
        if ($endsOn === null && $this->cancelledOn !== null) {
            $endsOn = $this->hasPeriods()
                ? $this->periodFor($this->cancelledOn)['end']
                : ($this->notice?->endIfGivenOn($this->cancelledOn) ?? $this->cancelledOn);
        }
        if ($endsOn !== null) {
            return new ContractTerm(
                state: $today > $endsOn ? ContractTerm::ENDED : ContractTerm::CANCELLED,
                endsOn: $endsOn,
                daysUntilEnd: Day::between($today, $endsOn),
            );
        }

        if (!$this->hasPeriods()) {
            return new ContractTerm(
                state: ContractTerm::OPEN,
                endIfCancelledToday: $this->notice?->endIfGivenOn($today),
            );
        }

        if (!$this->extensionMonths) {
            $end = Day::periodEnd($this->start, $this->firstPeriodMonths());
            return new ContractTerm(
                state: $today > $end ? ContractTerm::ENDED : ContractTerm::EXPIRES,
                periodStart: $this->start,
                periodEnd: $end,
                endsOn: $end,
                daysUntilEnd: Day::between($today, $end),
            );
        }

        $period = $this->periodFor($today);
        $days = Day::between($today, $period['noticeBy']);
        return new ContractTerm(
            state: ContractTerm::RUNNING,
            periodStart: $period['start'],
            countdownFrom: $period['countdownFrom'],
            periodEnd: $period['end'],
            noticeBy: $period['noticeBy'],
            daysUntilNotice: $days,
            status: Urgency::of($days, $lead),
            renewsMonths: $this->extensionMonths,
            endIfCancelledToday: $period['end'],
        );
    }

    /**
     * Upcoming price changes: each gives a special right to cancel until the day
     * before it takes effect. The oldest row is the start price, not a change.
     *
     * @return list<array{effective: string, noticeBy: string, daysUntil: int, status: string}>
     */
    public function priceChanges(string $today): array
    {
        $term = $this->term($today);
        if ($term->state === ContractTerm::ENDED) {
            return [];
        }
        $changes = [];
        foreach (array_slice($this->priceDates, 0, -1) as $effective) {
            if ($effective <= $today || ($term->endsOn !== null && $term->endsOn < $effective)) {
                continue;
            }
            $noticeBy = Day::addDays($effective, -1);
            $days = Day::between($today, $noticeBy);
            $changes[] = ['effective' => $effective, 'noticeBy' => $noticeBy, 'daysUntil' => $days, 'status' => Urgency::of($days, Urgency::CONTRACT_LEAD_DAYS)];
        }
        return array_reverse($changes);
    }

    /** Cost per month, or null for one-off payments and unknown rhythms. */
    public function monthlyCost(): ?float
    {
        $months = self::MONTHS_PER_RHYTHM[$this->rhythm] ?? null;
        return $this->cost === null || $months === null ? null : $this->cost / $months;
    }

    public function isInsurance(): bool
    {
        return $this->category === 'versicherung';
    }

    private function hasPeriods(): bool
    {
        return $this->start !== null && ($this->minimumMonths > 0 || $this->extensionMonths > 0);
    }

    private function firstPeriodMonths(): int
    {
        return $this->minimumMonths > 0 ? $this->minimumMonths : (int) $this->extensionMonths;
    }

    /**
     * The period that $day can still cancel: the first whose notice deadline is not before $day.
     *
     * @return array{start: string, end: string, noticeBy: string, countdownFrom: string}
     */
    private function periodFor(string $day): array
    {
        $months = $this->firstPeriodMonths();
        $start = $this->start;
        $countdownFrom = $this->start;
        // Periods are counted from the contract start each time, so month ends do not drift.
        for ($i = 0; $i < 1200; $i++) {
            $end = Day::periodEnd($this->start, $months);
            $noticeBy = $this->notice?->latestNoticeFor($end) ?? $end;
            if ($noticeBy >= $day || !$this->extensionMonths) {
                break;
            }
            $start = Day::addDays($end, 1);
            $countdownFrom = Day::addDays($noticeBy, 1);
            $months += $this->extensionMonths;
        }
        return ['start' => $start, 'end' => $end, 'noticeBy' => $noticeBy, 'countdownFrom' => min($countdownFrom, $noticeBy)];
    }
}
