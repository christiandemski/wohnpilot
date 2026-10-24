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

        self::assertSame('5 Fristen, 1 überfällig', $message['title']);
        self::assertSame(
            "Mieterhöhung zustimmen: seit 3 Tagen überfällig\nGlasfaser endet: heute\nStadtwerke kündigen: noch 7 Tage, bis 31.10.\nSonderkündigung Gas: noch 12 Tage, bis 05.11.\nund 1 weitere",
            $message['body'],
        );
        self::assertSame('/', $message['url']);
    }
}
