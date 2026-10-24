<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Domain\UtilityStatement;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** Utility statements: result, deadlines (§ 556 Abs. 3 BGB), comparison, meter check, § 35a. */
final class UtilityStatementTest extends TestCase
{
    private static function statement(string $id): UtilityStatement
    {
        return UtilityStatement::fromNote(NoteDocument::parse(Fixtures::read("objekte/efh-musterstrasse/nebenkosten/$id.md")));
    }

    public function testResultIsCostsMinusPrepayments(): void
    {
        $s = self::statement('betriebskosten-2025');
        self::assertSame('Betriebskosten 2025', $s->title());
        self::assertSame(3665.8, $s->total());
        self::assertSame(665.8, $s->balance(), 'back payment');
        self::assertSame(365, $s->days());

        self::assertNull(self::statement('heizkosten-2025')->balance(), 'billed through the landlord, no prepayments of its own');
    }

    public function testDeadlinesFollowTheMonthEnds(): void
    {
        $s = self::statement('betriebskosten-2025');
        self::assertSame('2026-12-31', $s->deliveryDeadline(), 'end of the 12th month after the period');
        self::assertFalse($s->deliveredLate());
        self::assertSame('2027-09-30', $s->objectionDeadline(), 'end of the 12th month after receipt');
        self::assertSame('2026-10-30', $s->paymentDue(), '30 days to check it');

        self::assertNull(self::statement('betriebskosten-2024')->paymentDue(), 'paid on 2025-12-10');
    }

    public function testLateStatementCannotAskForMore(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/nebenkosten/betriebskosten-2025.md'));
        $note->frontmatter()->set('zugang', '2027-01-05');
        $late = UtilityStatement::fromNote($note);

        self::assertTrue($late->deliveredLate());
        self::assertNull($late->paymentDue(), 'no back payment to make');
        self::assertSame('2028-01-31', $late->objectionDeadline());
    }

    public function testComparesPerYearWithTheMoveInMonth(): void
    {
        $rows = self::statement('betriebskosten-2025')->compareWith(self::statement('betriebskosten-2024'));
        $tax = $rows[0];
        self::assertSame('Grundsteuer', $tax['kind']);
        self::assertSame(412.8, $tax['perYear']);
        self::assertSame(405.03, $tax['previousPerYear'], '34,40 € for 31 days, as a year');
        self::assertSame(1.9, $tax['change']);

        $chimney = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'Schornsteinfeger'))[0];
        self::assertNull($chimney['previousPerYear'], 'new this year');
        self::assertNull(self::statement('betriebskosten-2025')->compareWith(null)[0]['change']);
    }

    public function testBilledWaterAgainstOwnReadings(): void
    {
        $readings = [new Reading('2025-01-01', 100.0), new Reading('2025-07-01', 160.0), new Reading('2026-01-01', 210.0)];
        $water = new Meter('wasser-haupt', 'wasser', 'm³', 5, 3, 'keller', 'monatlich', null, $readings);
        $partly = new Meter('wasser-garten', 'wasser', 'm³', 5, 3, 'garten', 'monatlich', null, [new Reading('2025-07-01', 5.0), new Reading('2026-01-01', 9.0)]);

        $check = self::statement('betriebskosten-2025')->checkAgainst([$water])[0];
        self::assertSame(['wasser_m3', 'm³', 112.4, 'wasser-haupt', 110.0, 365, 365, 2.2], array_values($check));

        $unread = self::statement('betriebskosten-2025')->checkAgainst([$partly])[0];
        self::assertNull($unread['measured'], 'half a year read says nothing about the year');
        self::assertSame(184, $unread['coveredDays']);
        self::assertNull(self::statement('betriebskosten-2025')->checkAgainst([])[0]['meterId']);
    }

    public function testTaxSharesAreTheLabourParts(): void
    {
        self::assertSame(
            [['kind' => 'Wartung Gastherme', 'taxKind' => 'handwerker', 'amount' => 130.0], ['kind' => 'Abgasmessung', 'taxKind' => 'handwerker', 'amount' => 35.0]],
            self::statement('heizkosten-2025')->taxShares(),
        );
    }

    public function testPeriodOverTwoYearsInTheTitle(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/nebenkosten/heizkosten-2025.md'));
        $note->frontmatter()->set('von', '2024-07-01');
        $note->frontmatter()->set('bis', '2025-06-30');
        self::assertSame('Heizkosten 2024/25', UtilityStatement::fromNote($note)->title());
    }
}
