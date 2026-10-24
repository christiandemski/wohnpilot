<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\MaintenanceSuggestions;
use Wohnpilot\Domain\MaintenanceTask;
use Wohnpilot\Domain\Season;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** Recurring tasks: next due date, season windows and suggestions (today 2026-10-24). */
final class MaintenanceTest extends TestCase
{
    private const TODAY = '2026-10-24';

    private static function fixture(string $name): MaintenanceTask
    {
        return MaintenanceTask::fromNote(NoteDocument::parse(Fixtures::read("objekte/efh-musterstrasse/wartung/$name.md")));
    }

    private static function task(array $done, ?Season $season = null, ?string $start = null, ?int $months = 12, ?int $weeks = null): MaintenanceTask
    {
        return new MaintenanceTask('t', 'Test', $months, $weeks, $season, $start, 14, 'mieter', $done);
    }

    public function testNextDueIsLastDonePlusInterval(): void
    {
        $smoke = self::fixture('rauchmelder')->evaluate(self::TODAY);

        self::assertSame(['2026-11-08', 15, 'ok', '2025-11-08'], [$smoke['dueDate'], $smoke['daysUntil'], $smoke['status'], $smoke['lastDone']]);
        self::assertNull($smoke['from']);
        self::assertSame('soon', self::fixture('rauchmelder')->evaluate('2026-10-25')['status'], '14 days lead');
        self::assertSame([-296, 'due'], array_values(array_intersect_key(self::task(['2025-01-01'])->evaluate(self::TODAY), ['daysUntil' => 0, 'status' => 0])));
    }

    public function testNeverDoneIsDueOnItsStartOrNow(): void
    {
        $heating = self::fixture('heizungswartung')->evaluate(self::TODAY);
        self::assertSame(['2026-11-01', 8, 'soon', null], [$heating['dueDate'], $heating['daysUntil'], $heating['status'], $heating['lastDone']]);

        $now = self::task([])->evaluate(self::TODAY);
        self::assertSame([self::TODAY, 0, 'due'], [$now['dueDate'], $now['daysUntil'], $now['status']]);
    }

    public function testWeeklyIntervalAndUnsortedHistory(): void
    {
        self::assertSame('2026-11-04', self::task(['2026-10-21'], months: null, weeks: 2)->evaluate(self::TODAY)['dueDate']);

        $note = NoteDocument::parse("---\ntyp: wartung\nid: x\nintervall_monate: 6\n---\n| Datum | Erledigt von | Notiz |\n|---|---|---|\n| 2026-01-10 | a | |\n| 2026-04-02 | b | |\n| kein Datum | c | |\n");
        self::assertSame('2026-10-02', MaintenanceTask::fromNote($note)->evaluate(self::TODAY)['dueDate'], 'newest date counts, wherever it stands');
    }

    public function testSeasonalTaskIsDueByTheEndOfItsWindow(): void
    {
        $hedge = self::fixture('heckenschnitt')->evaluate(self::TODAY);

        // Done 14.02.2026 → next season October 2026 to February 2027
        self::assertSame(['2026-10-01', '2027-02-28', 127, 'ok', true, false], [$hedge['from'], $hedge['dueDate'], $hedge['daysUntil'], $hedge['status'], $hedge['inSeason'], $hedge['missedSeason']]);
        self::assertSame('soon', self::fixture('heckenschnitt')->evaluate('2027-02-20')['status']);
    }

    public function testMissedSeasonMovesOnInsteadOfStayingOverdue(): void
    {
        $winter = new Season(10, 2);

        // Last done January 2025, the season 2025/26 passed without it
        $missed = self::task(['2025-01-25'], $winter)->evaluate(self::TODAY);
        self::assertSame(['2026-10-01', '2027-02-28', true], [$missed['from'], $missed['dueDate'], $missed['missedSeason']]);

        // Out of season in summer: the next window
        $summer = self::task(['2025-01-25'], $winter)->evaluate('2026-06-15');
        self::assertSame(['2026-10-01', '2027-02-28', false], [$summer['from'], $summer['dueDate'], $summer['inSeason']]);
    }

    public function testNeverDoneSeasonalTask(): void
    {
        $autumn = self::task([], new Season(9, 10))->evaluate('2026-06-01');

        self::assertSame(['2026-09-01', '2026-10-31'], [$autumn['from'], $autumn['dueDate']]);
    }

    /** @return array<string, array{int, int, string, ?array{string, string}}> */
    public static function windows(): array
    {
        return [
            'wrap, autumn part' => [10, 2, '2026-10-24', ['2026-10-01', '2027-02-28']],
            'wrap, winter part' => [10, 2, '2027-01-15', ['2026-10-01', '2027-02-28']],
            'wrap, leap February' => [10, 2, '2028-02-10', ['2027-10-01', '2028-02-29']],
            'wrap, outside' => [10, 2, '2026-03-01', null],
            'plain, inside' => [9, 10, '2026-09-30', ['2026-09-01', '2026-10-31']],
            'plain, outside' => [9, 10, '2026-11-01', null],
            'one month' => [3, 3, '2026-03-31', ['2026-03-01', '2026-03-31']],
        ];
    }

    #[DataProvider('windows')]
    public function testSeasonWindows(int $from, int $to, string $day, ?array $expected): void
    {
        self::assertSame($expected, (new Season($from, $to))->windowContaining($day));
    }

    public function testNextWindowAfterAGap(): void
    {
        self::assertSame(['2026-10-01', '2027-02-28'], (new Season(10, 2))->windowFrom('2026-03-01'));
        self::assertSame(['2027-09-01', '2027-10-31'], (new Season(9, 10))->windowFrom('2026-11-01'));
        self::assertNull(Season::fromValue(['von' => 13, 'bis' => 2]));
        self::assertNull(Season::fromValue('Oktober bis Februar'));
    }

    public function testSuggestionsFitTheHome(): void
    {
        $object = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/_objekt.md'))->frontmatter()->all();

        $suggestions = MaintenanceSuggestions::for($object, ['rauchmelder', 'heckenschnitt', 'heizungswartung']);

        self::assertSame(
            ['heizkoerper-entlueften', 'abfluesse-reinigen', 'abgasmessung', 'kamin-kehren', 'kamin-dichtungen', 'rasenmaeher-einwintern', 'winterdienst-ausruestung', 'aussenwasserhahn'],
            array_column($suggestions, 'id'),
        );
        $sweep = $suggestions[array_search('kamin-kehren', array_column($suggestions, 'id'), true)];
        self::assertSame(['dienstleister', 'wohnzimmer', 'heizung:kamin', null], [$sweep['zustaendig'], $sweep['raum'], $sweep['grund'], $sweep['saison']]);
        self::assertSame(['von' => 10, 'bis' => 11], $suggestions[array_search('winterdienst-ausruestung', array_column($suggestions, 'id'), true)]['saison']);
    }

    public function testFlatGetsNoGardenOrHouseTasks(): void
    {
        $ids = array_column(MaintenanceSuggestions::for(['art' => 'wohnung', 'heizung' => ['fernwaerme'], 'raeume' => ['bad']], []), 'id');

        self::assertSame(['rauchmelder', 'heizkoerper-entlueften', 'abfluesse-reinigen'], $ids);
        self::assertNull(MaintenanceSuggestions::for(['art' => 'wohnung', 'raeume' => ['bad']], [])[0]['raum'], 'no hallway in this flat');
    }
}
