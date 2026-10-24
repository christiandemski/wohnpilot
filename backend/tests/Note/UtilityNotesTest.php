<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** The utility statements in the fixtures (stage 5): format and consistency. */
final class UtilityNotesTest extends TestCase
{
    private const FOLDER = 'objekte/efh-musterstrasse/nebenkosten';
    private const COLUMNS = ['Kostenart', 'Betrag €', '§ 35a', 'davon § 35a €'];

    /** @return array<string, array{string}> */
    public static function statements(): array
    {
        return array_filter(Fixtures::allNotes(), static fn (array $n): bool => str_starts_with($n[0], self::FOLDER . '/'));
    }

    #[DataProvider('statements')]
    public function testStatementHasPeriodReceiptAndPositions(string $path): void
    {
        $note = NoteDocument::parse(Fixtures::read($path));
        $fm = $note->frontmatter();

        self::assertSame('nebenkosten', $fm->get('typ'));
        self::assertContains($fm->get('art'), ['betriebskosten', 'heizkosten']);
        foreach (['von', 'bis', 'zugang'] as $key) {
            self::assertTrue(Day::isValid((string) $fm->get($key)), "$key is an ISO date");
        }
        self::assertLessThan((string) $fm->get('zugang'), (string) $fm->get('bis'), 'received after the period');

        $table = $note->body()->table();
        self::assertSame(self::COLUMNS, $table->headers());
        foreach ($table->rows() as $row) {
            self::assertNotNull(Reading::parseNumber($row['Betrag €']), "amount of {$row['Kostenart']}");
            self::assertContains(trim($row['§ 35a']), ['', 'haushaltsnah', 'handwerker']);
            if (trim($row['§ 35a']) !== '') {
                self::assertLessThanOrEqual(Reading::parseNumber($row['Betrag €']), Reading::parseNumber($row['davon § 35a €']), 'labour share within the amount');
            }
        }
    }

    public function testHeatingStatementIsThePositionInTheLandlordsStatement(): void
    {
        foreach (['2024', '2025'] as $year) {
            $heating = NoteDocument::parse(Fixtures::read(self::FOLDER . "/heizkosten-$year.md"));
            self::assertSame("betriebskosten-$year", $heating->frontmatter()->get('abrechnung'));
            $total = array_sum(array_map(static fn (array $r): float => Reading::parseNumber($r['Betrag €']), $heating->body()->table()->rows()));

            $landlord = NoteDocument::parse(Fixtures::read(self::FOLDER . "/betriebskosten-$year.md"));
            $position = array_values(array_filter($landlord->body()->table()->rows(), static fn (array $r): bool => str_starts_with($r['Kostenart'], 'Heiz')));
            self::assertEqualsWithDelta($total, Reading::parseNumber($position[0]['Betrag €']), 0.001, "heating $year");
        }
    }
}
