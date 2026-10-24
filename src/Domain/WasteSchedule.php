<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Pickups of the bins from the municipal calendar. An event belongs to the
 * first bin whose keyword (`stichwort`, else its name) appears in the
 * summary, ignoring case; others, like the hazardous waste truck, stay
 * as they are without a bin.
 */
final class WasteSchedule
{
    /**
     * @param list<array{id: string, name: string, farbe?: string, stichwort?: string}> $bins
     * @param list<array{date: string, time: ?string, summary: string}> $events from IcsReader
     * @return list<array{date: string, time: ?string, summary: string, bin: ?string}>
     */
    public static function pickups(array $bins, array $events): array
    {
        $pickups = [];
        foreach ($events as $event) {
            $bin = null;
            foreach ($bins as $candidate) {
                $keyword = trim((string) ($candidate['stichwort'] ?? $candidate['name'] ?? ''));
                if ($keyword !== '' && mb_stripos($event['summary'], $keyword) !== false) {
                    $bin = (string) $candidate['id'];
                    break;
                }
            }
            $pickups[] = ['date' => $event['date'], 'time' => $event['time'], 'summary' => $event['summary'], 'bin' => $bin];
        }
        return $pickups;
    }

    /**
     * @param list<array{date: string, bin: ?string}> $pickups
     * @return array<string, string> bin id => next pickup on or after $today
     */
    public static function nextPerBin(array $pickups, string $today): array
    {
        $next = [];
        foreach ($pickups as $pickup) {
            if ($pickup['bin'] !== null && $pickup['date'] >= $today && (!isset($next[$pickup['bin']]) || $pickup['date'] < $next[$pickup['bin']])) {
                $next[$pickup['bin']] = $pickup['date'];
            }
        }
        return $next;
    }
}
