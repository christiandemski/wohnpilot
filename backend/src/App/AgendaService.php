<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\ContractTerm;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Device;
use Wohnpilot\Domain\Deadline;
use Wohnpilot\Domain\ReadingSchedule;
use Wohnpilot\Domain\Urgency;

/**
 * "Was steht an": every dated thing from contracts, lease, deadlines,
 * meters and the house (maintenance, appointments, defects to follow up,
 * warranties ending, waste pickups, utility statements) in one list, grouped by urgency, plus
 * the key figures of the overview.
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
    /** Waste pickups in the overview: this week only. */
    public const PICKUP_DAYS = 7;

    public function __construct(
        private readonly ContractService $contracts,
        private readonly LeaseService $lease,
        private readonly DeadlineService $deadlines,
        private readonly Clock $clock,
        private readonly ?MaintenanceService $maintenance = null,
        private readonly ?AppointmentService $appointments = null,
        private readonly ?DefectService $defects = null,
        private readonly ?DeviceService $devices = null,
        private readonly ?WasteService $waste = null,
        private readonly ?UtilityService $utilities = null,
        private readonly ?HouseholdService $household = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $meters meter summaries of the overview (MeterService::list)
     * @param int $pickupDays how far ahead waste pickups are listed (the calendar wants more)
     * @return array{agenda: list<array<string, mixed>>, figures: array<string, mixed>}
     */
    public function forOverview(array $meters, int $pickupDays = self::PICKUP_DAYS): array
    {
        $today = $this->clock->today();
        $this->names = $this->household?->names() ?? [];
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
                + ['from' => $deadline['windowStart'], 'meterIds' => $open, 'person' => $this->person($deadline['fields'])];
        }

        $voluntary = array_values(array_filter($meters, static fn (array $m): bool => $m['request'] === null && $m['schedule']['status'] === ReadingSchedule::STATUS_OPEN));
        if ($voluntary !== []) {
            usort($voluntary, static fn (array $a, array $b): int => $a['schedule']['dueDate'] <=> $b['schedule']['dueDate']);
            $first = $voluntary[0]['schedule'];
            $items[] = ['ref' => ['type' => 'ablesung', 'id' => null], 'name' => null, 'category' => null, 'kind' => 'ablesung', 'date' => $first['dueDate'], 'daysUntil' => $first['daysUntil'], 'status' => ReadingSchedule::STATUS_OPEN, 'group' => self::GROUP_HINT, 'meterIds' => array_column($voluntary, 'id')];
        }

        [$houseItems, $openDefects] = $this->house($today, $pickupDays);
        array_push($items, ...$houseItems);

        // Utility statements: back payment, objection deadline, a late statement
        foreach ($this->utilities?->agendaItems($today) ?? [] as $item) {
            $items[] = $item + ['group' => self::dated($item['kind'], $item['date'], $item['daysUntil'], $item['status'])['group']];
        }

        usort($items, static fn (array $a, array $b): int => [$a['group'] === self::GROUP_HINT, $a['date'], $a['kind']] <=> [$b['group'] === self::GROUP_HINT, $b['date'], $b['kind']]);

        return [
            'agenda' => $items,
            'figures' => [
                'contractsMonthly' => round($monthly, 2),
                'rentMonthly' => $rent === null ? null : round($rent, 2),
                'fixedMonthly' => round($monthly + ($rent ?? 0.0), 2),
                'nextNotice' => $nextNotice,
                'openDefects' => $openDefects,
            ],
        ];
    }

    /** @var array<string, string> person id => name while building the list */
    private array $names = [];

    /** @return array{id: string, name: string}|null who takes care of it; a person who left keeps their id as name */
    private function person(array $fields): ?array
    {
        $id = $fields['person'] ?? null;
        return is_string($id) && $id !== '' ? ['id' => $id, 'name' => $this->names[$id] ?? $id] : null;
    }

    /**
     * Maintenance (not the ones checked by radio), appointments from today,
     * defects with a follow-up day, warranties ending within 30 days, and
     * waste pickups of the next $pickupDays days.
     *
     * @return array{list<array<string, mixed>>, ?int} items and the number of open defects
     */
    private function house(string $today, int $pickupDays): array
    {
        $items = [];
        foreach ($this->maintenance?->list() ?? [] as $task) {
            $e = $task['evaluation'];
            if ($e['automatic']) {
                continue;
            }
            $items[] = ['ref' => ['type' => 'wartung', 'id' => $task['id']], 'name' => $task['title'], 'category' => null]
                + self::dated('wartung', $e['dueDate'], $e['daysUntil'], $e['status'])
                + ['from' => $e['from'], 'lastDone' => $e['lastDone'], 'appointment' => ($task['fields']['ausfuehrung'] ?? null) === 'termin', 'responsible' => $task['fields']['zustaendig'] ?? 'mieter', 'person' => $this->person($task['fields'])];
        }

        foreach ($this->appointments?->all() ?? [] as $appointment) {
            if ($appointment['daysUntil'] < 0) {
                continue;
            }
            $items[] = ['ref' => ['type' => 'termin', 'id' => $appointment['id']], 'name' => $appointment['title'], 'category' => null]
                + self::dated('termin', $appointment['date'], $appointment['daysUntil'], Urgency::OK)
                + ['time' => $appointment['time'], 'until' => $appointment['fields']['bis'] ?? null, 'place' => $appointment['fields']['ort'] ?? null, 'person' => $this->person($appointment['fields'])];
        }

        $openDefects = null;
        if ($this->defects !== null) {
            $openDefects = 0;
            foreach ($this->defects->list() as $defect) {
                $e = $defect['evaluation'];
                $openDefects += $e['open'] ? 1 : 0;
                if ($e['followUp'] !== null) {
                    $items[] = ['ref' => ['type' => 'mangel', 'id' => $defect['id']], 'name' => $defect['title'], 'category' => null]
                        + self::dated('nachfassen', $e['followUp']['date'], $e['followUp']['daysUntil'], $e['followUp']['status'])
                        + ['defectStatus' => $e['status']];
                }
            }
        }

        foreach ($this->devices?->list() ?? [] as $device) {
            // The end that comes first, while it is still ahead and close
            $ends = array_filter(
                ['gewaehrleistung' => $device['evaluation']['liability'], 'garantie' => $device['evaluation']['warranty']],
                static fn (?array $end): bool => $end !== null && $end['daysUntil'] >= 0 && $end['daysUntil'] <= Device::WARRANTY_LEAD_DAYS,
            );
            uasort($ends, static fn (array $a, array $b): int => $a['daysUntil'] <=> $b['daysUntil']);
            if ($ends !== []) {
                $which = array_key_first($ends);
                $end = $ends[$which];
                $items[] = ['ref' => ['type' => 'geraet', 'id' => $device['id']], 'name' => $device['name'], 'category' => null]
                    + self::dated('garantie', $end['until'], $end['daysUntil'], $end['status'])
                    + ['which' => $which];
            }
        }

        if ($this->waste !== null) {
            $waste = $this->waste->get();
            $bins = array_column($waste['bins'], null, 'id');
            foreach ($waste['pickups'] as $pickup) {
                $days = Day::between($today, $pickup['date']);
                if ($pickup['bin'] === null || $days < 0 || $days > $pickupDays) {
                    continue;
                }
                $bin = $bins[$pickup['bin']] ?? ['name' => $pickup['summary'], 'farbe' => 'bin-rest'];
                $items[] = ['ref' => ['type' => 'muell', 'id' => $pickup['bin']], 'name' => $bin['name'], 'category' => null]
                    + self::dated('muell', $pickup['date'], $days, Urgency::OK)
                    + ['color' => $bin['farbe'] ?? 'bin-rest', 'fullService' => $waste['fullService'], 'time' => $pickup['time']];
            }
        }
        return [$items, $openDefects];
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
