<?php

declare(strict_types=1);

namespace Wohnpilot\Push;

use Wohnpilot\Domain\Day;

/**
 * The morning push: only on days when a deadline reaches a milestone
 * (30, 14, 7, 3 days, the day before, the day itself, overdue since 1, 3 or
 * 7 days) or a requested reading can start. Then it lists everything that is
 * close, so one push says it all. Voluntary readings never push.
 */
final class DailyDigest
{
    public const MILESTONES = [30, 14, 7, 3, 1, 0, -1, -3, -7];
    /** Everything this close is listed once a push goes out. */
    private const CLOSE_DAYS = 14;
    private const MAX_LINES = 4;

    /**
     * @param list<array<string, mixed>> $agenda items of AgendaService::forOverview
     * @return array{title: string, body: string, url: string, tag: string}|null null if there is nothing to say today
     */
    public function build(array $agenda, string $today): ?array
    {
        $items = array_values(array_filter($agenda, static fn (array $i): bool => $i['kind'] !== 'ablesung'));
        $triggered = array_filter($items, static fn (array $i): bool => self::isMilestone($i, $today));
        if ($triggered === []) {
            return null;
        }

        $listed = array_values(array_filter(
            $items,
            static fn (array $i): bool => $i['daysUntil'] <= self::CLOSE_DAYS || in_array($i, $triggered, true),
        ));
        usort($listed, static fn (array $a, array $b): int => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);

        if (count($listed) === 1) {
            $item = $listed[0];
            return [
                'title' => self::subject($item),
                'body' => self::when($item, $today),
                'url' => self::path($item),
                'tag' => 'wohnpilot-digest',
            ];
        }

        $lines = array_map(static fn (array $i): string => self::subject($i) . ': ' . self::when($i, $today), array_slice($listed, 0, self::MAX_LINES));
        $more = count($listed) - self::MAX_LINES;
        if ($more > 0) {
            $lines[] = "und $more weitere";
        }
        $overdue = count(array_filter($listed, static fn (array $i): bool => $i['daysUntil'] < 0));
        return [
            'title' => $overdue > 0 ? count($listed) . " Fristen, $overdue überfällig" : count($listed) . ' Fristen stehen an',
            'body' => implode("\n", $lines),
            'url' => '/',
            'tag' => 'wohnpilot-digest',
        ];
    }

    private static function isMilestone(array $item, string $today): bool
    {
        if ($item['kind'] === 'frist' && !empty($item['meterIds']) && ($item['from'] ?? null) === $today) {
            return true;
        }
        return in_array($item['daysUntil'], self::MILESTONES, true);
    }

    /** "Stadtwerke kündigen", "Mieterhöhung zustimmen" */
    private static function subject(array $item): string
    {
        $name = (string) ($item['name'] ?? '');
        return match ($item['kind']) {
            'kuendigung' => "$name kündigen",
            'vertragsende' => empty($item['cancelled']) ? "$name läuft aus" : "$name endet",
            'sonderkuendigung' => "Sonderkündigung $name",
            'zustimmung' => 'Mieterhöhung zustimmen',
            default => $name,
        };
    }

    /** "noch 9 Tage, bis 02.11.", "heute", "seit 2 Tagen überfällig" */
    private static function when(array $item, string $today): string
    {
        $days = (int) $item['daysUntil'];
        $date = Day::parse((string) $item['date'])->format('d.m.');
        if ($item['kind'] === 'frist' && !empty($item['meterIds']) && ($item['from'] ?? null) === $today) {
            return "ab heute ablesen, melden bis $date";
        }
        return match (true) {
            $days < -1 => 'seit ' . -$days . ' Tagen überfällig',
            $days === -1 => 'seit gestern überfällig',
            $days === 0 => 'heute',
            $days === 1 => 'morgen',
            default => "noch $days Tage, bis $date",
        };
    }

    private static function path(array $item): string
    {
        $id = rawurlencode((string) ($item['ref']['id'] ?? ''));
        return match ($item['ref']['type'] ?? '') {
            'vertrag' => "/vertraege/$id",
            'mietvertrag' => '/mietvertrag',
            'frist' => "/fristen/$id",
            default => '/',
        };
    }
}
