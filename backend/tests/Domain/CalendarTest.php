<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\NoticePeriod;
use Wohnpilot\Domain\WorkingDay;

final class CalendarTest extends TestCase
{
    /** @return array<string, array{string, int, string}> */
    public static function periodEnds(): array
    {
        return [
            'two years from the first' => ['2024-12-01', 24, '2026-11-30'],
            '31st into a leap February' => ['2024-01-31', 1, '2024-02-29'],
            '30th into a leap February' => ['2024-01-30', 1, '2024-02-29'],
            '29th has a corresponding day' => ['2024-01-29', 1, '2024-02-28'],
            '31st into a common February' => ['2025-01-31', 1, '2025-02-28'],
            'leap day plus a year' => ['2024-02-29', 12, '2025-02-28'],
            'year ending on a leap day' => ['2023-03-01', 12, '2024-02-29'],
            'mid month' => ['2024-11-15', 24, '2026-11-14'],
        ];
    }

    #[DataProvider('periodEnds')]
    public function testPeriodEnd(string $start, int $months, string $end): void
    {
        self::assertSame($end, Day::periodEnd($start, $months));
    }

    public function testAddMonthsToDateClamps(): void
    {
        self::assertSame('2024-02-29', Day::addMonthsToDate('2024-01-31', 1));
        self::assertSame('2026-02-28', Day::addMonthsToDate('2026-05-31', -3));
        self::assertSame('2027-10-24', Day::addMonthsToDate('2026-10-24', 12));
    }

    /** @return array<string, array{NoticePeriod, string, string}> */
    public static function noticeDeadlines(): array
    {
        return [
            '4 weeks before 30.11. (mockup)' => [NoticePeriod::weeks(4), '2026-11-30', '2026-11-02'],
            '3 months before year end' => [NoticePeriod::months(3), '2026-12-31', '2026-09-30'],
            '1 month before end of November' => [NoticePeriod::months(1), '2026-11-30', '2026-10-31'],
            '1 month before end of February' => [NoticePeriod::months(1), '2027-02-28', '2027-01-31'],
            'missing day errs early' => [NoticePeriod::months(3), '2026-05-30', '2026-02-27'],
            'no notice period' => [NoticePeriod::weeks(0), '2026-11-30', '2026-11-30'],
        ];
    }

    #[DataProvider('noticeDeadlines')]
    public function testLatestNotice(NoticePeriod $notice, string $end, string $noticeBy): void
    {
        self::assertSame($noticeBy, $notice->latestNoticeFor($end));
    }

    public function testNoticeFieldsPreferMonths(): void
    {
        self::assertEquals(NoticePeriod::months(3), NoticePeriod::fromFields(4, 3));
        self::assertEquals(NoticePeriod::weeks(4), NoticePeriod::fromFields(4, null));
        self::assertNull(NoticePeriod::fromFields(null, null));
    }

    public function testEasterSunday(): void
    {
        self::assertSame('2024-03-31', WorkingDay::easterSunday(2024));
        self::assertSame('2026-04-05', WorkingDay::easterSunday(2026));
        self::assertSame('2027-03-28', WorkingDay::easterSunday(2027));
    }

    public function testNationwideHolidays(): void
    {
        foreach (['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14', '2026-05-25', '2026-10-03', '2026-12-25', '2026-12-26'] as $day) {
            self::assertTrue(WorkingDay::isNationwideHoliday($day), $day);
        }
        self::assertFalse(WorkingDay::isNationwideHoliday('2026-04-05'), 'Easter Sunday is a Sunday anyway, not counted as holiday');
        self::assertFalse(WorkingDay::isNationwideHoliday('2026-11-01'), 'All Saints is regional');
    }

    /** @return array<string, array{string, string}> */
    public static function thirdWorkingDays(): array
    {
        return [
            'German Unity Day and Sunday skipped' => ['2026-10', '2026-10-05'],
            'month starting on Sunday' => ['2026-11', '2026-11-04'],
            'Saturday counts' => ['2026-08', '2026-08-04'],
            'New Year skipped' => ['2027-01', '2027-01-05'],
        ];
    }

    #[DataProvider('thirdWorkingDays')]
    public function testThirdWorkingDay(string $month, string $day): void
    {
        self::assertSame($day, WorkingDay::nth($month, 3));
    }
}
