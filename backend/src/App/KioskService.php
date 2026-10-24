<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;

/**
 * The board of the kiosk tablet: what is due today and in the next days,
 * the next waste pickups and this month's consumption. Read-mostly; the
 * only write is ticking off maintenance (MaintenanceService).
 */
final class KioskService
{
    /** Days ahead the board looks */
    public const DAYS = 7;
    public const PICKUPS = 3;
    /** Kinds that make no sense on the wall: voluntary readings, waste (own tile) */
    private const SKIPPED = ['ablesung', 'muell'];

    public function __construct(
        private readonly MeterService $meters,
        private readonly AgendaService $agenda,
        private readonly WasteService $waste,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
    ) {
    }

    public function board(): array
    {
        $today = $this->clock->today();
        $meters = $this->meters->list();
        $items = array_values(array_filter(
            $this->agenda->forOverview($meters)['agenda'],
            static fn (array $i): bool => !in_array($i['kind'], self::SKIPPED, true)
                && $i['daysUntil'] <= self::DAYS
                // The landlord's maintenance is only information
                && !($i['kind'] === 'wartung' && ($i['responsible'] ?? 'mieter') === 'vermieter'),
        ));

        $waste = $this->waste->get();
        $bins = array_column($waste['bins'], null, 'id');
        $pickups = [];
        foreach ($waste['pickups'] as $pickup) {
            if ($pickup['bin'] === null || $pickup['date'] < $today) {
                continue;
            }
            $bin = $bins[$pickup['bin']] ?? null;
            $pickups[] = ['date' => $pickup['date'], 'daysUntil' => Day::between($today, $pickup['date']), 'name' => $bin['name'] ?? $pickup['summary'], 'color' => $bin['farbe'] ?? 'bin-rest'];
            if (count($pickups) === self::PICKUPS) {
                break;
            }
        }

        return [
            'today' => $today,
            'household' => $this->household->household()['name'] ?? null,
            'items' => $items,
            'waste' => ['configured' => $waste['configured'], 'fullService' => $waste['fullService'], 'pickups' => $pickups],
            'meters' => array_map(static fn (array $m): array => [
                'id' => $m['id'],
                'medium' => $m['medium'],
                'name' => $m['name'],
                'unit' => $m['unit'],
                'decimals' => $m['decimals'],
                'currentMonth' => $m['currentMonth'],
                'latest' => $m['latest'],
            ], $meters),
        ];
    }
}
