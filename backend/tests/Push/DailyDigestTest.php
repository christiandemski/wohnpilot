<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Push;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Push\DailyDigest;

final class DailyDigestTest extends TestCase
{
    private const TODAY = '2026-10-24';

    private static function item(array $changes): array
    {
        return $changes + [
            'ref' => ['type' => 'vertrag', 'id' => 'strom-stadtwerke'],
            'name' => 'Stadtwerke',
            'kind' => 'kuendigung',
            'date' => '2026-10-31',
            'daysUntil' => 7,
            'status' => 'soon',
        ];
    }

    public function testQuietDaysSendNothing(): void
    {
        $digest = new DailyDigest();

        self::assertNull($digest->build([], self::TODAY));
        self::assertNull($digest->build([self::item(['daysUntil' => 9, 'date' => '2026-11-02'])], self::TODAY), 'no milestone today');
        self::assertNull($digest->build([self::item(['kind' => 'ablesung', 'daysUntil' => 0, 'status' => 'open'])], self::TODAY), 'voluntary readings never push');
    }

    public function testOneDeadlineGetsItsOwnMessage(): void
    {
        self::assertSame(
            ['title' => 'Stadtwerke kündigen', 'body' => 'noch 7 Tage, bis 31.10.', 'url' => '/vertraege/strom-stadtwerke', 'tag' => 'wohnpilot-digest'],
            (new DailyDigest())->build([self::item([])], self::TODAY),
        );
    }

    public function testRequestedReadingPushesWhenItsWindowOpens(): void
    {
        $reading = self::item(['ref' => ['type' => 'frist', 'id' => 'strom-ablesung'], 'name' => 'Zählerstand Strom melden', 'kind' => 'frist', 'date' => '2026-11-08', 'daysUntil' => 15, 'status' => 'ok', 'from' => self::TODAY, 'meterIds' => ['strom-haupt']]);

        self::assertSame(
            ['title' => 'Zählerstand Strom melden', 'body' => 'ab heute ablesen, melden bis 08.11.', 'url' => '/fristen/strom-ablesung', 'tag' => 'wohnpilot-digest'],
            (new DailyDigest())->build([$reading], self::TODAY),
        );
    }

    public function testOnePushListsEverythingClose(): void
    {
        $message = (new DailyDigest())->build([
            self::item(['daysUntil' => -3, 'date' => '2026-10-21', 'status' => 'due', 'kind' => 'zustimmung', 'ref' => ['type' => 'mietvertrag', 'id' => 'm'], 'name' => null]),
            self::item(['daysUntil' => 0, 'date' => '2026-10-24', 'name' => 'Glasfaser', 'kind' => 'vertragsende', 'cancelled' => true]),
            self::item([]),
            self::item(['daysUntil' => 12, 'date' => '2026-11-05', 'kind' => 'sonderkuendigung', 'name' => 'Gas']),
            self::item(['daysUntil' => 13, 'date' => '2026-11-06', 'name' => 'Handy']),
            self::item(['daysUntil' => 60, 'date' => '2026-12-23', 'name' => 'Hausrat', 'status' => 'ok']),
        ], self::TODAY);

        self::assertSame('5 Punkte, 1 überfällig', $message['title']);
        self::assertSame(
            "Mieterhöhung zustimmen: seit 3 Tagen überfällig\nGlasfaser endet: heute\nStadtwerke kündigen: noch 7 Tage, bis 31.10.\nSonderkündigung Gas: noch 12 Tage, bis 05.11.\nund 1 weitere",
            $message['body'],
        );
        self::assertSame('/', $message['url']);
    }

    public function testHouseHasItsOwnDays(): void
    {
        $digest = new DailyDigest();
        $appointment = self::item(['ref' => ['type' => 'termin', 'id' => 'schornsteinfeger'], 'name' => 'Schornsteinfeger', 'kind' => 'termin', 'status' => 'ok', 'time' => '08:00']);
        $bin = self::item(['ref' => ['type' => 'muell', 'id' => 'gelb'], 'name' => 'Gelbe Tonne', 'kind' => 'muell', 'status' => 'ok', 'fullService' => false]);

        self::assertNull($digest->build([$appointment + ['daysUntil' => 7, 'date' => '2026-10-31']], self::TODAY), 'a week ahead is no reason');
        self::assertSame(
            ['title' => 'Schornsteinfeger', 'body' => 'morgen um 08:00 Uhr', 'url' => '/termine/schornsteinfeger', 'tag' => 'wohnpilot-digest'],
            $digest->build([['daysUntil' => 1, 'date' => '2026-10-25'] + $appointment], self::TODAY),
        );
        self::assertSame('Gelbe Tonne rausstellen', $digest->build([['daysUntil' => 1, 'date' => '2026-10-25'] + $bin], self::TODAY)['title']);
        self::assertNull($digest->build([['daysUntil' => 1, 'date' => '2026-10-25', 'fullService' => true] + $bin], self::TODAY), 'full service never pushes');

        $warranty = self::item(['ref' => ['type' => 'geraet', 'id' => 'waschmaschine'], 'name' => 'Waschmaschine', 'kind' => 'garantie', 'which' => 'gewaehrleistung', 'status' => 'soon']);
        self::assertSame(['Gewährleistung Waschmaschine', 'endet in 30 Tagen, am 23.11.'], array_values(array_intersect_key($digest->build([['daysUntil' => 30, 'date' => '2026-11-23'] + $warranty], self::TODAY), ['title' => 0, 'body' => 0])));
        self::assertNull($digest->build([['daysUntil' => 26, 'date' => '2026-11-19'] + $warranty], self::TODAY));

        $followUp = self::item(['ref' => ['type' => 'mangel', 'id' => 'fenster'], 'name' => 'Fenster zieht', 'kind' => 'nachfassen', 'daysUntil' => 0, 'date' => self::TODAY, 'status' => 'due']);
        self::assertSame(['Nachfassen: Fenster zieht', '/maengel/fenster'], array_values(array_intersect_key($digest->build([$followUp], self::TODAY), ['title' => 0, 'url' => 0])));
    }

    public function testLandlordsMaintenanceDoesNotPush(): void
    {
        $boiler = self::item(['ref' => ['type' => 'wartung', 'id' => 'heizungswartung'], 'name' => 'Gastherme warten lassen', 'kind' => 'wartung', 'responsible' => 'vermieter']);

        self::assertNull((new DailyDigest())->build([$boiler], self::TODAY));
        self::assertSame('Stadtwerke kündigen', (new DailyDigest())->build([self::item([]), $boiler], self::TODAY)['title'], 'not listed either');
        self::assertSame('Gastherme warten lassen', (new DailyDigest())->build([['responsible' => 'dienstleister'] + $boiler], self::TODAY)['title']);
    }

    public function testCalmHouseThingsDoNotFillTheList(): void
    {
        $message = (new DailyDigest())->build([
            self::item([]),
            self::item(['ref' => ['type' => 'muell', 'id' => 'bio'], 'name' => 'Bio', 'kind' => 'muell', 'daysUntil' => 3, 'date' => '2026-10-27', 'status' => 'ok', 'fullService' => true]),
            self::item(['ref' => ['type' => 'termin', 'id' => 't'], 'name' => 'Handwerker', 'kind' => 'termin', 'daysUntil' => 5, 'date' => '2026-10-29', 'status' => 'ok']),
        ], self::TODAY);

        self::assertSame('Stadtwerke kündigen', $message['title'], 'pickups with full service and appointments days away stay out');
    }
}
