<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Consumption;
use Wohnpilot\Domain\CostEstimate;
use Wohnpilot\Domain\Plausibility;
use Wohnpilot\Domain\ReadingSchedule;

final class CheckAndScheduleTest extends TestCase
{
    public function testUsualReadingIsOk(): void
    {
        $check = Plausibility::check(new Consumption(MeterFactory::strom()), 23770.0, '2026-11-01');

        self::assertSame(Plausibility::OK, $check['result']);
        self::assertEqualsWithDelta(68.0, $check['delta'], 1e-9);
        self::assertSame(8, $check['days']);
        self::assertEqualsWithDelta(8.5, $check['perDay'], 1e-9);
    }

    public function testLowerThanLastReading(): void
    {
        $check = Plausibility::check(new Consumption(MeterFactory::strom()), 23700.0, '2026-11-01');

        self::assertSame(Plausibility::LOWER, $check['result']);
        self::assertEqualsWithDelta(-2.0, $check['delta'], 1e-9);
    }

    public function testUnusuallyHigh(): void
    {
        // usual: 710 kWh in 84 days ≈ 8.5 per day; 248 in 8 days is far above twice that
        $check = Plausibility::check(new Consumption(MeterFactory::strom()), 23950.0, '2026-11-01');

        self::assertSame(Plausibility::HIGH, $check['result']);
    }

    public function testFirstReadingAndNoHistory(): void
    {
        self::assertSame(Plausibility::FIRST, Plausibility::check(new Consumption(MeterFactory::withRows([])), 5.0, '2026-10-24')['result']);
        // With one reading there is no usual consumption yet, so any increase is fine.
        self::assertSame(Plausibility::OK, Plausibility::check(new Consumption(MeterFactory::withRows([['2026-10-01', '1']])), 9999.0, '2026-10-24')['result']);
    }

    /** @return array<string, array{string, string, string, int, string}> */
    public static function schedules(): array
    {
        return [
            'monthly, next first of month' => ['monatlich', '2026-10-24', '2026-10-24', 1, '2026-11-01'],
            'reading shortly before counts for next date' => ['monatlich', '2026-10-30', '2026-10-30', 1, '2026-12-01'],
            'reading day clamps to month length' => ['monatlich', '2026-10-31', '2026-11-02', 31, '2026-11-30'],
            'quarterly' => ['quartalsweise', '2026-10-02', '2026-10-24', 1, '2027-01-01'],
            'quarterly from mid quarter' => ['quartalsweise', '2026-05-20', '2026-05-20', 1, '2026-07-01'],
            'yearly' => ['jaehrlich', '2026-03-15', '2026-10-24', 1, '2027-01-01'],
            'overdue keeps first missed date' => ['monatlich', '2026-07-01', '2026-10-24', 1, '2026-08-01'],
        ];
    }

    #[DataProvider('schedules')]
    public function testNextDueDate(string $interval, string $lastReading, string $today, int $readingDay, string $expected): void
    {
        $meter = MeterFactory::withRows([[$lastReading, '1']], "ablese_intervall: $interval\n");

        self::assertSame($expected, ReadingSchedule::next($meter, $readingDay, $today)['dueDate']);
    }

    public function testDueStatus(): void
    {
        $meter = MeterFactory::strom();

        self::assertSame(['dueDate' => '2026-11-01', 'daysUntil' => 8, 'status' => 'ok'], ReadingSchedule::next($meter, 1, '2026-10-24'));
        self::assertSame('soon', ReadingSchedule::next($meter, 1, '2026-10-26')['status']);
        self::assertSame('due', ReadingSchedule::next($meter, 1, '2026-11-01')['status']);
        self::assertSame(-1, ReadingSchedule::next($meter, 1, '2026-11-02')['daysUntil']);
        self::assertSame(['dueDate' => '2026-10-24', 'daysUntil' => 0, 'status' => 'due'], ReadingSchedule::next(MeterFactory::withRows([]), 1, '2026-10-24'));
    }

    public function testCostEstimateAgainstInstalments(): void
    {
        $estimate = CostEstimate::forYear(new Consumption(MeterFactory::strom()), '2026-10-24');

        $amount = 710 + 281 * 710 / 84;
        $cost = $amount * 0.298 + 12 * 12.5;
        self::assertSame('2026-01-01', $estimate['periodStart']);
        self::assertSame('2027-01-01', $estimate['periodEnd']);
        self::assertEqualsWithDelta($amount, $estimate['projectedAmount'], 1e-6);
        self::assertEqualsWithDelta($cost, $estimate['cost'], 1e-6);
        self::assertEqualsWithDelta(1020.0, $estimate['paid'], 1e-9);
        self::assertEqualsWithDelta($cost - 1020.0, $estimate['balance'], 1e-6);
        self::assertSame((int) ceil($cost / 12), $estimate['suggestedInstalment']);
    }

    public function testCostEstimateNeedsTariff(): void
    {
        self::assertNull(CostEstimate::forYear(new Consumption(MeterFactory::withRows([['2026-10-01', '1'], ['2026-09-01', '0']])), '2026-10-24'));
    }

    public function testBillingPeriod(): void
    {
        self::assertSame(['2026-01-01', '2027-01-01'], CostEstimate::period('2026-10-24'));
        self::assertSame(['2025-03-01', '2026-03-01'], CostEstimate::period('2026-02-10', '03-01'));
        self::assertSame(['2026-03-01', '2027-03-01'], CostEstimate::period('2026-03-01', '03-01'));
    }
}
