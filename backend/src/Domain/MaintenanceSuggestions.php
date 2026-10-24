<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Tasks to tap on instead of an empty list, from the home: every home gets
 * the basics, heating types (`heizung`) and tenant duties (`mieterpflichten`)
 * add theirs (see the table in docs/konzept.md, Einrichtung). Tasks that
 * already exist (same id) are left out.
 */
final class MaintenanceSuggestions
{
    /**
     * id => [titel, intervall_monate, zustaendig, saison|null, raum|null, why]
     * why: 'basis', 'heizung:<art>', 'pflicht:<pflicht>' or 'haus'
     */
    private const TEMPLATES = [
        'rauchmelder' => ['Rauchmelder testen', 12, 'mieter', null, 'flur', 'basis'],
        'heizkoerper-entlueften' => ['Heizkörper entlüften', 12, 'mieter', [9, 10], null, 'basis'],
        'abfluesse-reinigen' => ['Siphons und Abflüsse reinigen', 6, 'mieter', null, 'bad', 'basis'],
        'heizungswartung' => ['Gastherme warten lassen', 12, 'vermieter', null, 'keller', 'heizung:gas'],
        'abgasmessung' => ['Schornsteinfeger: Abgasmessung', 24, 'dienstleister', null, 'keller', 'heizung:gas'],
        'oelheizung-wartung' => ['Ölheizung warten lassen', 12, 'vermieter', null, 'keller', 'heizung:oel'],
        'oeltank-pruefen' => ['Öltank: Füllstand prüfen', 3, 'mieter', null, 'keller', 'heizung:oel'],
        'pelletlager-reinigen' => ['Pelletlager reinigen', 12, 'vermieter', [5, 8], 'keller', 'heizung:pellets'],
        'waermepumpe-filter' => ['Wärmepumpe: Filter reinigen', 6, 'mieter', null, null, 'heizung:waermepumpe'],
        'waermepumpe-wartung' => ['Wärmepumpe warten lassen', 12, 'vermieter', null, null, 'heizung:waermepumpe'],
        'kamin-kehren' => ['Schornsteinfeger: Kaminofen kehren', 12, 'dienstleister', null, 'wohnzimmer', 'heizung:kamin'],
        'kamin-dichtungen' => ['Kaminofen: Dichtungen und Asche prüfen', 6, 'mieter', [9, 3], 'wohnzimmer', 'heizung:kamin'],
        'heckenschnitt' => ['Hecke zurückschneiden', 12, 'mieter', [10, 2], 'garten', 'pflicht:garten'],
        'rasenmaeher-einwintern' => ['Rasenmäher einwintern', 12, 'mieter', [10, 11], 'garten', 'pflicht:garten'],
        'winterdienst-ausruestung' => ['Winterdienst: Streugut und Schneeschieber prüfen', 12, 'mieter', [10, 11], null, 'pflicht:winterdienst'],
        'dachrinne-reinigen' => ['Dachrinne reinigen', 12, 'mieter', [11, 12], 'garten', 'pflicht:dachrinne'],
        'aussenwasserhahn' => ['Außenwasserhahn vor Frost absperren', 12, 'mieter', [10, 11], 'garten', 'haus'],
    ];

    /**
     * @param array<string, mixed> $object fields of `_objekt.md` (art, heizung, mieterpflichten)
     * @param list<string> $existing ids of the tasks there are already
     * @return list<array{id: string, titel: string, intervall_monate: int, zustaendig: string, saison: ?array{von: int, bis: int}, raum: ?string, grund: string}>
     */
    public static function for(array $object, array $existing): array
    {
        $heating = (array) ($object['heizung'] ?? []);
        $duties = (array) ($object['mieterpflichten'] ?? []);
        $house = ($object['art'] ?? '') === 'haus';
        $rooms = (array) ($object['raeume'] ?? []);

        $suggestions = [];
        foreach (self::TEMPLATES as $id => [$title, $months, $responsible, $season, $room, $why]) {
            [$kind, $value] = array_pad(explode(':', $why, 2), 2, '');
            $fits = match ($kind) {
                'basis' => true,
                'heizung' => in_array($value, $heating, true),
                'pflicht' => in_array($value, $duties, true),
                'haus' => $house,
                default => false,
            };
            if (!$fits || in_array($id, $existing, true)) {
                continue;
            }
            $suggestions[] = [
                'id' => $id,
                'titel' => $title,
                'intervall_monate' => $months,
                'zustaendig' => $responsible,
                'saison' => $season === null ? null : ['von' => $season[0], 'bis' => $season[1]],
                // Only rooms the home has
                'raum' => $room !== null && in_array($room, $rooms, true) ? $room : null,
                'grund' => $why,
            ];
        }
        return $suggestions;
    }
}
