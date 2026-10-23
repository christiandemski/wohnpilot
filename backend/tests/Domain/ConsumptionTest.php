<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Consumption;

final class ConsumptionTest extends TestCase
{
    public function testIntervalsOfTheFixture(): void
    {
        $intervals = (new Consumption(MeterFactory::strom()))->intervals();

        self::assertSame(
            [
                ['from' => '2026-08-01', 'to' => '2026-09-01', 'days' => 31, 'amount' => 226.0],
                ['from' => '2026-09-01', 'to' => '2026-10-01', 'days' => 30, 'amount' => 238.0],
                ['from' => '2026-10-01', 'to' => '2026-10-24', 'days' => 23, 'amount' => 246.0],
            ],
            $intervals,
        );
    }

    public function testMonthlyValuesOfTheFixture(): void
    {
        $months = (new Consumption(MeterFactory::strom()))->monthly('2026-10-24');

        self::assertCount(12, $months);
        self::assertSame('2025-11', $months[0]['month']);
        self::assertNull($months[0]['amount']);
        self::assertNull($months[8]['amount'], 'July has no readings');

        self::assertSame('2026-10', $months[11]['month']);
        self::assertEqualsWithDelta(246.0, $months[11]['amount'], 1e-9, 'October so far');
        self::assertSame(23, $months[11]['coveredDays']);
        self::assertSame(31, $months[11]['days']);
        self::assertEqualsWithDelta(238.0, $months[10]['amount'], 1e-9);
        self::assertEqualsWithDelta(226.0, $months[9]['amount'], 1e-9);
        self::assertNull($months[11]['previousYear']);
    }

    public function testIntervalAcrossMonthsIsSplitByDays(): void
    {
        $meter = MeterFactory::withRows([['2026-02-15', '310'], ['2026-01-15', '0']]);

        $months = (new Consumption($meter))->monthly('2026-02-20', 2);

        self::assertEqualsWithDelta(170.0, $months[0]['amount'], 1e-9);
        self::assertEqualsWithDelta(140.0, $months[1]['amount'], 1e-9);
    }

    public function testLeapYearFebruary(): void
    {
        $meter = MeterFactory::withRows([['2028-03-01', '290'], ['2028-02-01', '0']]);
        $consumption = new Consumption($meter);

        self::assertSame(29, $consumption->intervals()[0]['days']);
        self::assertEqualsWithDelta(290.0, $consumption->monthly('2028-02-29', 1)[0]['amount'], 1e-9);
    }

    public function testPreviousYearIsTheSameMonthOneYearEarlier(): void
    {
        $meter = MeterFactory::withRows([
            ['2026-11-01', '1300'],
            ['2026-10-01', '1000'],
            ['2025-11-01', '400'],
            ['2025-10-01', '0'],
        ]);

        $october = (new Consumption($meter))->monthly('2026-10-31', 1)[0];

        self::assertEqualsWithDelta(300.0, $october['amount'], 1e-9);
        self::assertEqualsWithDelta(400.0, $october['previousYear'], 1e-9);
    }

    public function testMeterChangeAddsNoConsumption(): void
    {
        $meter = MeterFactory::withRows([
            ['2026-04-01', '31'],
            ['2026-03-01', '0', 'Zählerwechsel'],
            ['2026-03-01', '160', 'Zählerwechsel'],
            ['2026-01-01', '100'],
        ]);
        $consumption = new Consumption($meter);

        self::assertSame(
            [
                ['from' => '2026-01-01', 'to' => '2026-03-01', 'days' => 59, 'amount' => 60.0],
                ['from' => '2026-03-01', 'to' => '2026-04-01', 'days' => 31, 'amount' => 31.0],
            ],
            $consumption->intervals(),
        );
        self::assertEqualsWithDelta(91.0, $consumption->between('2026-01-01', '2026-04-01'), 1e-9);
        self::assertSame(0.0, $meter->latest()->value - 31.0);
    }

    public function testSecondReadingOnTheSameDayIsNotLost(): void
    {
        $meter = MeterFactory::withRows([
            ['2026-10-24', '23712.0'],
            ['2026-10-24', '23702.0'],
            ['2026-10-01', '23456.0'],
        ]);

        $october = (new Consumption($meter))->monthly('2026-10-24', 1)[0];

        self::assertEqualsWithDelta(256.0, $october['amount'], 1e-9);
    }

    public function testSingleReadingHasNoConsumption(): void
    {
        $consumption = new Consumption(MeterFactory::withRows([['2026-10-01', '1234.567']]));

        self::assertSame([], $consumption->intervals());
        self::assertNull($consumption->typicalDailyRate('2026-10-24'));
        self::assertNull($consumption->projection('2026-01-01', '2027-01-01', '2026-10-24'));
        self::assertNull($consumption->monthly('2026-10-24', 1)[0]['amount']);
    }

    public function testTypicalDailyRate(): void
    {
        $consumption = new Consumption(MeterFactory::strom());

        self::assertEqualsWithDelta(710 / 84, $consumption->typicalDailyRate('2026-10-24'), 1e-9);
    }

    public function testProjectionFillsUnknownDaysWithTheTypicalRate(): void
    {
        $projection = (new Consumption(MeterFactory::strom()))->projection('2026-01-01', '2027-01-01', '2026-10-24');

        self::assertEqualsWithDelta(710.0, $projection['measured'], 1e-9);
        self::assertSame(281, $projection['estimatedDays']);
        self::assertEqualsWithDelta(710 + 281 * 710 / 84, $projection['amount'], 1e-6);
    }

    public function testProjectionUsesLastYearWhereKnown(): void
    {
        // 2025: 10 per day. 2026 first half: 10 per day as well, rest comes from 2025.
        $meter = MeterFactory::withRows([
            ['2026-07-01', '5460'],
            ['2026-01-01', '3650'],
            ['2025-01-01', '0'],
        ]);

        $projection = (new Consumption($meter))->projection('2026-01-01', '2027-01-01', '2026-07-01');

        self::assertEqualsWithDelta(1810.0, $projection['measured'], 1e-9);
        self::assertEqualsWithDelta(3650.0, $projection['amount'], 1e-6);
    }

    public function testYearly(): void
    {
        $years = (new Consumption(MeterFactory::strom()))->yearly();

        self::assertCount(1, $years);
        self::assertSame(2026, $years[0]['year']);
        self::assertEqualsWithDelta(710.0, $years[0]['amount'], 1e-9);
        self::assertSame(84, $years[0]['coveredDays']);
    }
}
