<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Calendar;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Calendar\IcsFeed;

final class IcsFeedTest extends TestCase
{
    private const STAMP = '20261024T080000Z';

    private static function item(array $changes): array
    {
        return $changes + [
            'ref' => ['type' => 'vertrag', 'id' => 'strom-stadtwerke'],
            'name' => 'Stadtwerke',
            'category' => 'energie',
            'kind' => 'kuendigung',
            'date' => '2026-11-02',
            'daysUntil' => 9,
            'status' => 'soon',
            'group' => 'bald',
            'periodEnd' => '2026-11-30',
            'renewsMonths' => 1,
        ];
    }

    private static function feed(array $agenda): string
    {
        return (new IcsFeed('https://wohnpilot.example.org'))->build($agenda, self::STAMP);
    }

    /** Lines with folded continuations joined again (RFC 5545, 3.1). */
    private static function unfold(string $ics): array
    {
        return explode("\r\n", str_replace("\r\n ", '', rtrim($ics, "\r\n")));
    }

    public function testNoticeDayIsAnAllDayEventWithReminders(): void
    {
        $lines = self::unfold(self::feed([self::item([])]));

        self::assertSame('BEGIN:VCALENDAR', $lines[0]);
        self::assertSame('END:VCALENDAR', end($lines));
        foreach ([
            'UID:kuendigung-strom-stadtwerke-2026-11-02@wohnpilot',
            'DTSTAMP:20261024T080000Z',
            'DTSTART;VALUE=DATE:20261102',
            'DTEND;VALUE=DATE:20261103',
            'SUMMARY:Letzter Tag: Stadtwerke kündigen',
            'DESCRIPTION:Die Kündigung muss heute beim Anbieter sein. Sonst verlängert sich der Vertrag um 1 Monat.\n\nhttps://wohnpilot.example.org/vertraege/strom-stadtwerke',
            'URL:https://wohnpilot.example.org/vertraege/strom-stadtwerke',
            'TRIGGER:-P6DT15H',
            'TRIGGER:-PT15H',
        ] as $expected) {
            self::assertContains($expected, $lines);
        }
    }

    public function testTextsForTheOtherKinds(): void
    {
        $lines = self::unfold(self::feed([
            self::item(['kind' => 'vertragsende', 'ref' => ['type' => 'vertrag', 'id' => 'internet-glasfaser'], 'name' => 'Glasfaser', 'date' => '2026-11-14', 'cancelled' => true]),
            self::item(['kind' => 'sonderkuendigung', 'date' => '2026-12-31', 'effective' => '2027-01-01']),
            self::item(['kind' => 'zustimmung', 'ref' => ['type' => 'mietvertrag', 'id' => 'mietvertrag-efh'], 'name' => null, 'date' => '2026-12-31', 'validFrom' => '2027-01-01', 'coldRent' => 1495.0]),
            self::item(['kind' => 'frist', 'ref' => ['type' => 'frist', 'id' => 'strom-ablesung-2026'], 'name' => 'Zählerstand Strom melden', 'date' => '2026-12-20', 'from' => '2026-12-05', 'meterIds' => ['strom-haupt']]),
            self::item(['kind' => 'ablesung', 'ref' => ['type' => 'ablesung', 'id' => null], 'name' => null, 'group' => 'hinweis']),
        ]));

        self::assertContains('SUMMARY:Glasfaser endet', $lines);
        self::assertContains('SUMMARY:Letzter Tag: Sonderkündigung Stadtwerke', $lines);
        self::assertContains('SUMMARY:Letzter Tag: Mieterhöhung zustimmen', $lines);
        self::assertContains('DESCRIPTION:Neue Miete ab 01.01.2027\, 1.495 € kalt. Prüf Mietspiegel und Begründung\, bevor du zustimmst.\n\nhttps://wohnpilot.example.org/mietvertrag', $lines);
        self::assertContains('SUMMARY:Zählerstand Strom melden', $lines);
        self::assertContains('DESCRIPTION:Ablesen ab 05.12.2026.\n\nhttps://wohnpilot.example.org/fristen/strom-ablesung-2026', $lines);
        self::assertCount(4, array_keys($lines, 'BEGIN:VEVENT'), 'voluntary readings are no event');
    }

    public function testEscapesAndFoldsLongLines(): void
    {
        $ics = self::feed([self::item(['kind' => 'frist', 'ref' => ['type' => 'frist', 'id' => 'f'], 'name' => str_repeat('Mülltonne; Keller, Garten ', 6)])]);

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'never cut inside a character');
        }
        self::assertContains('SUMMARY:' . str_repeat('Mülltonne\; Keller\, Garten ', 6), self::unfold($ics));
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }
}
