<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Calendar\IcsReader;
use Wohnpilot\Domain\Defect;
use Wohnpilot\Domain\Device;
use Wohnpilot\Domain\MinorRepairs;
use Wohnpilot\Domain\WasteSchedule;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** Waste calendar, defects, minor repairs and devices (today 2026-10-24). */
final class HouseTest extends TestCase
{
    private const TODAY = '2026-10-24';
    private const OBJECT = 'objekte/efh-musterstrasse';

    private static function wasteCalendar(): string
    {
        return file_get_contents(dirname(Fixtures::root()) . '/abfuhrkalender.ics');
    }

    private static function bins(): array
    {
        return NoteDocument::parse(Fixtures::read(self::OBJECT . '/termine/muell.md'))->frontmatter()->get('tonnen');
    }

    private static function defect(string $name): Defect
    {
        return Defect::fromNote(NoteDocument::parse(Fixtures::read(self::OBJECT . "/maengel/$name.md")));
    }

    public function testReadsTheMunicipalCalendar(): void
    {
        $events = IcsReader::occurrences(self::wasteCalendar(), self::TODAY, '2026-12-31');

        self::assertSame(
            [
                ['2026-10-27', null, 'Gelbe Tonne'],
                ['2026-10-30', '06:00', 'Bioabfall'],
                ['2026-11-03', null, 'Restabfall 14-täglich'],
                ['2026-11-06', null, 'Altpapier'],
                ['2026-11-10', null, 'Gelbe Tonne'],
                ['2026-11-13', '06:00', 'Bioabfall'],
                ['2026-11-14', '09:00', 'Schadstoffmobil Marktplatz'],
                ['2026-11-17', null, 'Restabfall 14-täglich'],
                ['2026-11-24', null, 'Gelbe Tonne'],
                ['2026-12-01', null, 'Restabfall 14-täglich'],
                ['2026-12-04', null, 'Altpapier'],
                ['2026-12-08', null, 'Gelbe Tonne'],
                ['2026-12-23', null, 'Gelbe Tonne (verschoben wegen Feiertag)'],
            ],
            array_map(static fn (array $e): array => [$e['date'], $e['time'], $e['summary']], $events),
        );
    }

    public function testRulesOverridesAndTimeZones(): void
    {
        $ics = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261102\r\nRRULE:FREQ=MONTHLY;COUNT=3\r\nSUMMARY:Papier\\, blau\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:a\r\nRECURRENCE-ID;VALUE=DATE:20261202\r\nDTSTART;VALUE=DATE:20261203\r\nSUMMARY:Papier\\, blau\r\nEND:VEVENT\r\n"
            // 23:30 UTC is already the next day in Berlin
            . "BEGIN:VEVENT\r\nUID:b\r\nDTSTART:20261105T233000Z\r\nSUMMARY:Bio\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:c\r\nDTSTART;VALUE=DATE:20261101\r\nRRULE:FREQ=WEEKLY;INTERVAL=4\r\nSUMMARY:Rest\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $events = IcsReader::occurrences($ics, '2026-11-01', '2027-01-31');

        self::assertSame(
            [['2026-11-01', 'Rest'], ['2026-11-02', 'Papier, blau'], ['2026-11-06', 'Bio'], ['2026-11-29', 'Rest'], ['2026-12-03', 'Papier, blau'], ['2026-12-27', 'Rest'], ['2027-01-02', 'Papier, blau'], ['2027-01-24', 'Rest']],
            array_map(static fn (array $e): array => [$e['date'], $e['summary']], $events),
        );
        self::assertSame('00:30', $events[2]['time']);
        self::assertSame([], IcsReader::occurrences('kein Kalender', '2026-01-01', '2026-12-31'));
    }

    public function testPickupsBelongToTheirBins(): void
    {
        $pickups = WasteSchedule::pickups(self::bins(), IcsReader::occurrences(self::wasteCalendar(), self::TODAY, '2026-12-31'));

        self::assertSame(['gelb', 'bio', 'rest', 'papier'], array_slice(array_column($pickups, 'bin'), 0, 4));
        self::assertNull($pickups[6]['bin'], 'the hazardous waste truck is no bin');
        self::assertSame(['gelb' => '2026-10-27', 'bio' => '2026-10-30', 'rest' => '2026-11-03', 'papier' => '2026-11-06'], WasteSchedule::nextPerBin($pickups, self::TODAY));
        self::assertSame('2026-11-10', WasteSchedule::nextPerBin($pickups, '2026-10-28')['gelb']);
    }

    public function testOpenDefectWithFollowUp(): void
    {
        $window = self::defect('fenster-schlafzimmer');

        self::assertSame(
            ['status' => 'bestaetigt', 'open' => true, 'discovered' => '2026-10-05', 'reported' => '2026-10-06', 'fixedOn' => null, 'days' => 19, 'followUp' => ['date' => '2026-11-02', 'daysUntil' => 9, 'status' => 'ok']],
            $window->evaluate(self::TODAY),
        );
        self::assertSame('soon', $window->evaluate('2026-10-31')['followUp']['status']);
        self::assertSame(-3, $window->evaluate('2026-11-05')['followUp']['daysUntil']);
    }

    public function testFixedDefect(): void
    {
        $tap = self::defect('wasserhahn-bad')->evaluate(self::TODAY);

        self::assertSame(['behoben', false, '2026-09-18', '2026-09-20', 2, null], [$tap['status'], $tap['open'], $tap['discovered'], $tap['fixedOn'], $tap['days'], $tap['followUp']]);
    }

    public function testMinorRepairsAgainstTheLimits(): void
    {
        $big = new Defect('herd', 'Herdplatte defekt', [['date' => '2026-03-02', 'status' => 'behoben', 'channel' => '', 'note' => '']], minorRepair: true, cost: 180.0);
        $old = new Defect('alt', 'Alt', [['date' => '2025-12-30', 'status' => 'behoben', 'channel' => '', 'note' => '']], minorRepair: true, cost: 60.0);
        $notMinor = new Defect('x', 'Kein Fall', [['date' => '2026-05-01', 'status' => 'behoben', 'channel' => '', 'note' => '']], cost: 90.0);

        $year = MinorRepairs::forYear([self::defect('wasserhahn-bad'), $big, $old, $notMinor, self::defect('fenster-schlafzimmer')], 2026, 100.0, 300.0);

        self::assertSame([['herd', true], ['wasserhahn-bad', false]], array_map(static fn (array $c): array => [$c['id'], $c['overLimit']], $year['cases']));
        self::assertSame([38.5, 261.5, false], [$year['total'], $year['left'], $year['overYear']], 'above the limit per case: the landlord pays all of it');
    }

    public function testWarrantyAndLiability(): void
    {
        $washer = Device::fromNote(NoteDocument::parse(Fixtures::read(self::OBJECT . '/geraete/waschmaschine.md')))->evaluate(self::TODAY);

        self::assertSame(['until' => '2027-11-19', 'daysUntil' => 391, 'status' => 'ok'], $washer['warranty']);
        self::assertSame(['until' => '2026-11-19', 'daysUntil' => 26, 'status' => 'soon'], $washer['liability']);

        $boiler = Device::fromNote(NoteDocument::parse(Fixtures::read(self::OBJECT . '/geraete/gastherme.md')))->evaluate(self::TODAY);
        self::assertSame(['warranty' => null, 'liability' => null], $boiler, "the landlord's device");

        $expired = (new Device('d', 'Toaster', purchased: '2023-01-10', warrantyUntil: '2025-01-09'))->evaluate(self::TODAY);
        self::assertSame(['2025-01-09', 'due'], [$expired['warranty']['until'], $expired['warranty']['status']]);
    }
}
