<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Lease;
use Wohnpilot\Domain\Urgency;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class LeaseTest extends TestCase
{
    private const PATH = 'objekte/efh-musterstrasse/mietvertrag/mietvertrag.md';

    private static function lease(?callable $edit = null): Lease
    {
        $note = NoteDocument::parse(Fixtures::read(self::PATH));
        if ($edit !== null) {
            $edit($note);
        }
        return Lease::fromNote($note);
    }

    public function testReadsTheLease(): void
    {
        $lease = self::lease();

        self::assertSame('efh-musterstrasse', $lease->object);
        self::assertSame('2024-12-01', $lease->start);
        self::assertSame(3, $lease->noticeMonths);
        self::assertSame(4350, $lease->deposit['betrag_eur']);
        self::assertCount(3, $lease->history);
        self::assertSame('2027-01-01', $lease->history[0]->validFrom);
    }

    public function testRentInForceComesFromHistory(): void
    {
        $lease = self::lease();

        self::assertSame(['cold' => 1450.0, 'utilities' => 250.0, 'validFrom' => '2024-12-01'], $lease->rentOn('2025-06-01'));
        self::assertSame(['cold' => 1450.0, 'utilities' => 280.0, 'validFrom' => '2026-01-01'], $lease->rentOn('2026-10-24'));
        self::assertSame(['cold' => 1495.0, 'utilities' => 280.0, 'validFrom' => '2027-01-01'], $lease->rentOn('2027-01-01'));
    }

    public function testRentWithoutHistoryComesFromFrontmatter(): void
    {
        $lease = Lease::fromNote(NoteDocument::parse("---\ntyp: mietvertrag\nid: m\nbeginn: 2024-12-01\nkaltmiete_eur: 900\nnebenkosten_eur: 200\n---\n"));

        self::assertSame(['cold' => 900.0, 'utilities' => 200.0, 'validFrom' => '2024-12-01'], $lease->rentOn('2026-10-24'));
        self::assertSame(Lease::DEFAULT_NOTICE_MONTHS, $lease->noticeMonths);
    }

    public function testTenantNoticeAfterThirdWorkingDayCountsForNextMonth(): void
    {
        // October 2026: 1st Thu, 2nd Fri, 3rd holiday, 5th Mon is the third working day.
        self::assertSame(['noticeBy' => '2026-11-04', 'endsOn' => '2027-01-31', 'daysUntil' => 11], self::lease()->tenantNotice('2026-10-24'));
    }

    public function testTenantNoticeOnThirdWorkingDayStillCounts(): void
    {
        self::assertSame(['noticeBy' => '2026-10-05', 'endsOn' => '2026-12-31', 'daysUntil' => 0], self::lease()->tenantNotice('2026-10-05'));
        self::assertSame('2027-01-31', self::lease()->tenantNotice('2026-10-06')['endsOn']);
    }

    public function testTenantNoticeHonoursLongerPeriod(): void
    {
        $lease = self::lease(static fn (NoteDocument $n) => $n->frontmatter()->set('kuendigungsfrist_monate', 6));

        self::assertSame('2027-04-30', $lease->tenantNotice('2026-10-24')['endsOn']);
    }

    public function testRentIncreaseNeedsConsentByEndOfSecondMonth(): void
    {
        self::assertSame(
            [[
                'validFrom' => '2027-01-01',
                'received' => '2026-10-15',
                'consentBy' => '2026-12-31',
                'daysUntil' => 68,
                'status' => Urgency::OK,
                'coldRent' => 1495.0,
            ]],
            self::lease()->consentDeadlines('2026-10-24'),
        );
        self::assertSame(Urgency::SOON, self::lease()->consentDeadlines('2026-12-15')[0]['status']);
        self::assertSame(-1, self::lease()->consentDeadlines('2027-01-01')[0]['daysUntil'], 'stays until you note your answer');
    }

    public function testAgreedIncreaseHasNoDeadline(): void
    {
        $lease = self::lease(static fn (NoteDocument $n) => $n->body()->table()->replaceRow(0, ['Zugestimmt' => '2026-11-02']));

        self::assertSame([], $lease->consentDeadlines('2026-10-24'));
    }

    public function testIndexAndGraduatedRentNeedNoConsent(): void
    {
        foreach (['index', 'staffel'] as $type) {
            $lease = self::lease(static fn (NoteDocument $n) => $n->frontmatter()->set('mietart', $type));
            self::assertSame([], $lease->consentDeadlines('2026-10-24'), $type);
        }
    }
}
