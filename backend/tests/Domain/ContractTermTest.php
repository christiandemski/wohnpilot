<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Contract;
use Wohnpilot\Domain\ContractTerm;
use Wohnpilot\Domain\NoticePeriod;
use Wohnpilot\Domain\Urgency;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class ContractTermTest extends TestCase
{
    private static function fixture(string $name): Contract
    {
        return Contract::fromNote(NoteDocument::parse(Fixtures::read("vertraege/$name.md")));
    }

    public function testElectricityContractAsInMockup(): void
    {
        $term = self::fixture('strom-stadtwerke')->term('2026-10-24');

        self::assertSame(ContractTerm::RUNNING, $term->state);
        self::assertSame('2024-12-01', $term->periodStart);
        self::assertSame('2024-12-01', $term->countdownFrom);
        self::assertSame('2026-11-30', $term->periodEnd);
        self::assertSame('2026-11-02', $term->noticeBy);
        self::assertSame(9, $term->daysUntilNotice);
        self::assertSame(Urgency::SOON, $term->status);
        self::assertSame(1, $term->renewsMonths);
        self::assertSame('2026-11-30', $term->endIfCancelledToday);
    }

    public function testNoticeDayItselfIsDue(): void
    {
        $term = self::fixture('strom-stadtwerke')->term('2026-11-02');

        self::assertSame('2026-11-02', $term->noticeBy);
        self::assertSame(0, $term->daysUntilNotice);
        self::assertSame(Urgency::DUE, $term->status);
    }

    public function testMissedDeadlineMovesToNextRenewal(): void
    {
        $term = self::fixture('strom-stadtwerke')->term('2026-11-03');

        self::assertSame('2026-12-01', $term->periodStart);
        self::assertSame('2026-11-03', $term->countdownFrom);
        self::assertSame('2026-12-31', $term->periodEnd);
        self::assertSame('2026-12-03', $term->noticeBy);
        self::assertSame(30, $term->daysUntilNotice);
        self::assertSame(Urgency::SOON, $term->status);
    }

    public function testYearlyInsuranceSkipsPassedDeadlines(): void
    {
        $term = self::fixture('hausrat')->term('2026-10-24');

        self::assertSame(ContractTerm::RUNNING, $term->state);
        self::assertSame('2026-12-01', $term->periodStart);
        self::assertSame('2026-09-01', $term->countdownFrom);
        self::assertSame('2027-11-30', $term->periodEnd);
        self::assertSame('2027-08-31', $term->noticeBy);
        self::assertSame(311, $term->daysUntilNotice);
        self::assertSame(Urgency::OK, $term->status);
    }

    public function testCancelledContractEnds(): void
    {
        $contract = self::fixture('internet-glasfaser');

        $before = $contract->term('2026-10-24');
        self::assertSame(ContractTerm::CANCELLED, $before->state);
        self::assertSame('2026-11-14', $before->endsOn);
        self::assertSame(21, $before->daysUntilEnd);
        self::assertNull($before->noticeBy);

        self::assertSame(ContractTerm::CANCELLED, $contract->term('2026-11-14')->state, 'still running on its last day');
        self::assertSame(ContractTerm::ENDED, $contract->term('2026-11-15')->state);
    }

    public function testCancelledOnDerivesTheEnd(): void
    {
        $inTime = self::contract(cancelledOn: '2026-11-02');
        $late = self::contract(cancelledOn: '2026-11-03');

        self::assertSame('2026-11-30', $inTime->term('2026-11-10')->endsOn);
        self::assertSame('2026-12-31', $late->term('2026-11-10')->endsOn);
    }

    public function testExplicitEndWinsOverCancelledOn(): void
    {
        self::assertSame('2026-12-15', self::contract(cancelledOn: '2026-11-02', cancelledTo: '2026-12-15')->term('2026-11-10')->endsOn);
    }

    public function testFixedTermWithoutRenewalExpires(): void
    {
        $contract = new Contract('fest', 'sonstiges', 'X', start: '2026-01-01', minimumMonths: 12, extensionMonths: 0, notice: NoticePeriod::months(1));

        $term = $contract->term('2026-10-24');
        self::assertSame(ContractTerm::EXPIRES, $term->state);
        self::assertSame('2026-12-31', $term->endsOn);
        self::assertNull($term->noticeBy);
        self::assertSame(ContractTerm::ENDED, $contract->term('2027-01-01')->state);
    }

    public function testRollingContractWithoutMinimumTerm(): void
    {
        $contract = new Contract('monat', 'streaming', 'X', start: '2026-03-15', extensionMonths: 1, notice: NoticePeriod::weeks(0));

        $term = $contract->term('2026-10-24');
        self::assertSame('2026-10-15', $term->periodStart);
        self::assertSame('2026-11-14', $term->periodEnd);
        self::assertSame('2026-11-14', $term->noticeBy);
    }

    public function testContractWithoutTermCanEndAnyTime(): void
    {
        $contract = new Contract('rundfunk', 'abgabe', 'X', notice: NoticePeriod::months(1));

        $term = $contract->term('2026-10-24');
        self::assertSame(ContractTerm::OPEN, $term->state);
        self::assertSame('2026-11-24', $term->endIfCancelledToday);
        self::assertNull($term->status);
    }

    public function testOwnLeadTime(): void
    {
        self::assertSame(Urgency::OK, self::contract(leadDays: 5)->term('2026-10-24')->status);
        self::assertSame(Urgency::SOON, self::contract(leadDays: 9)->term('2026-10-24')->status);
    }

    public function testAnnouncedPriceChangeGivesSpecialTermination(): void
    {
        $contract = self::fixture('strom-stadtwerke');

        self::assertSame(
            [['effective' => '2027-01-01', 'noticeBy' => '2026-12-31', 'daysUntil' => 68, 'status' => Urgency::OK]],
            $contract->priceChanges('2026-10-24'),
        );
        self::assertSame(Urgency::SOON, $contract->priceChanges('2026-12-01')[0]['status']);
        self::assertSame([], $contract->priceChanges('2027-01-01'), 'in force, no longer a deadline');
    }

    public function testNoSpecialTerminationIfContractEndsFirst(): void
    {
        self::assertSame([], self::contract(cancelledTo: '2026-11-30', priceDates: ['2027-01-01', '2024-12-01'])->priceChanges('2026-10-24'));
        self::assertSame([], self::fixture('internet-glasfaser')->priceChanges('2026-10-24'), 'only past changes');
        self::assertSame([], self::contract(priceDates: ['2027-01-01'])->priceChanges('2026-10-24'), 'a single row is the start price');
    }

    public function testMonthlyCost(): void
    {
        self::assertEqualsWithDelta(85.0, self::fixture('strom-stadtwerke')->monthlyCost(), 1e-9);
        self::assertEqualsWithDelta(96.4 / 12, self::fixture('hausrat')->monthlyCost(), 1e-9);
        self::assertNull((new Contract('a', 'x', 'X', cost: 50.0, rhythm: 'einmalig'))->monthlyCost());
        self::assertNull((new Contract('a', 'x', 'X'))->monthlyCost());
    }

    public function testInsuranceFlag(): void
    {
        self::assertTrue(self::fixture('hausrat')->isInsurance());
        self::assertFalse(self::fixture('strom-stadtwerke')->isInsurance());
    }

    /** Like the electricity fixture: from 2024-12-01, 24 months, renews monthly, 4 weeks notice. */
    private static function contract(?string $cancelledOn = null, ?string $cancelledTo = null, ?int $leadDays = null, array $priceDates = []): Contract
    {
        return new Contract(
            'strom',
            'energie',
            'Stadtwerke',
            start: '2024-12-01',
            minimumMonths: 24,
            extensionMonths: 1,
            notice: NoticePeriod::weeks(4),
            cancelledOn: $cancelledOn,
            cancelledTo: $cancelledTo,
            leadDays: $leadDays,
            priceDates: $priceDates,
        );
    }
}
