<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\ContractTerm;
use Wohnpilot\Domain\Deadline;
use Wohnpilot\Domain\ReadingSchedule;
use Wohnpilot\Domain\Urgency;

/**
 * "Was steht an": every dated thing from contracts, lease, deadlines and
 * meters in one list, grouped by urgency, plus the key figures of the overview.
 * Items carry codes and names only; the frontend writes the sentences.
 */
final class AgendaService
{
    public const GROUP_OVERDUE = 'ueberfaellig';
    public const GROUP_WEEK = 'woche';
    public const GROUP_SOON = 'bald';
    public const GROUP_LATER = 'spaeter';
    /** Voluntary readings: never urgent, shown last. */
    public const GROUP_HINT = 'hinweis';

    private const WEEK_DAYS = 7;
    private const SOON_DAYS = 30;

    public function __construct(
        private readonly ContractService $contracts,
        private readonly LeaseService $lease,
        private readonly DeadlineService $deadlines,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $meters meter summaries of the overview (MeterService::list)
     * @return array{agenda: list<array<string, mixed>>, figures: array<string, mixed>}
     */
    public function forOverview(array $meters): array
    {
        $today = $this->clock->today();
        $items = [];
        $monthly = 0.0;
        $nextNotice = null;

        foreach ($this->contracts->active() as $contract) {
            $term = $contract->term($today);
            $ref = ['type' => 'vertrag', 'id' => $contract->id];
            $name = $contract->name !== '' ? $contract->name : $contract->provider;
            $base = ['ref' => $ref, 'name' => $name, 'category' => $contract->category];
            $monthly += $contract->monthlyCost() ?? 0.0;

            if ($term->state === ContractTerm::RUNNING) {
                $items[] = $base + self::dated('kuendigung', $term->noticeBy, $term->daysUntilNotice, $term->status) + ['periodEnd' => $term->periodEnd, 'renewsMonths' => $term->renewsMonths, 'countdownFrom' => $term->countdownFrom];
                if ($nextNotice === null || $term->noticeBy < $nextNotice['date']) {
                    $nextNotice = ['id' => $contract->id, 'name' => $name, 'category' => $contract->category, 'date' => $term->noticeBy, 'daysUntil' => $term->daysUntilNotice, 'status' => $term->status, 'countdownFrom' => $term->countdownFrom];
                }
            } elseif (in_array($term->state, [ContractTerm::CANCELLED, ContractTerm::EXPIRES], true)) {
                $items[] = $base + self::dated('vertragsende', $term->endsOn, $term->daysUntilEnd, Urgency::OK) + ['cancelled' => $term->state === ContractTerm::CANCELLED];
            }
            foreach ($contract->priceChanges($today) as $change) {
                $items[] = $base + self::dated('sonderkuendigung', $change['noticeBy'], $change['daysUntil'], $change['status']) + ['effective' => $change['effective']];
            }
        }

        $lease = $this->lease->lease();
        $rent = null;
        if ($lease !== null) {
            foreach ($lease->consentDeadlines($today) as $consent) {
                $items[] = ['ref' => ['type' => 'mietvertrag', 'id' => $lease->id], 'name' => null, 'category' => null]
                    + self::dated('zustimmung', $consent['consentBy'], $consent['daysUntil'], $consent['status'])
                    + ['validFrom' => $consent['validFrom'], 'received' => $consent['received'], 'coldRent' => $consent['coldRent']];
            }
            $now = $lease->rentOn($today);
            $rent = ($now['cold'] ?? 0.0) + ($now['utilities'] ?? 0.0);
        }

        foreach ($this->deadlines->all() as $deadline) {
            $evaluation = $deadline['evaluation'];
            if ($evaluation['status'] === Deadline::DONE) {
                continue;
            }
            $open = array_values(array_column(array_filter($evaluation['meters'], static fn (array $m): bool => $m['readOn'] === null), 'id'));
            $items[] = ['ref' => ['type' => 'frist', 'id' => $deadline['id']], 'name' => $deadline['title'], 'category' => null]
                + self::dated('frist', $deadline['fields']['datum'], $evaluation['daysUntil'], $evaluation['status'])
                + ['from' => $deadline['windowStart'], 'meterIds' => $open];
        }

        $voluntary = array_values(array_filter($meters, static fn (array $m): bool => $m['request'] === null && $m['schedule']['status'] === ReadingSchedule::STATUS_OPEN));
        if ($voluntary !== []) {
            usort($voluntary, static fn (array $a, array $b): int => $a['schedule']['dueDate'] <=> $b['schedule']['dueDate']);
            $first = $voluntary[0]['schedule'];
            $items[] = ['ref' => ['type' => 'ablesung', 'id' => null], 'name' => null, 'category' => null, 'kind' => 'ablesung', 'date' => $first['dueDate'], 'daysUntil' => $first['daysUntil'], 'status' => ReadingSchedule::STATUS_OPEN, 'group' => self::GROUP_HINT, 'meterIds' => array_column($voluntary, 'id')];
        }

        usort($items, static fn (array $a, array $b): int => [$a['group'] === self::GROUP_HINT, $a['date'], $a['kind']] <=> [$b['group'] === self::GROUP_HINT, $b['date'], $b['kind']]);

        return [
            'agenda' => $items,
            'figures' => [
                'contractsMonthly' => round($monthly, 2),
                'rentMonthly' => $rent === null ? null : round($rent, 2),
                'fixedMonthly' => round($monthly + ($rent ?? 0.0), 2),
                'nextNotice' => $nextNotice,
            ],
        ];
    }

    /** @return array{kind: string, date: string, daysUntil: int, status: string, group: string} */
    private static function dated(string $kind, string $date, int $daysUntil, string $status): array
    {
        $group = match (true) {
            $daysUntil < 0 => self::GROUP_OVERDUE,
            $daysUntil <= self::WEEK_DAYS => self::GROUP_WEEK,
            $daysUntil <= self::SOON_DAYS => self::GROUP_SOON,
            default => self::GROUP_LATER,
        };
        return ['kind' => $kind, 'date' => $date, 'daysUntil' => $daysUntil, 'status' => $status, 'group' => $group];
    }
}
