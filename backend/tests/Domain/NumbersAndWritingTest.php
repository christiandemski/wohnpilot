<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\GermanNumber;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\MeterNote;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class NumbersAndWritingTest extends TestCase
{
    private const NNBSP = "\u{202F}";

    public function testGermanNumberFormat(): void
    {
        self::assertSame('23' . self::NNBSP . '702,0', GermanNumber::format(23702.0, 1));
        self::assertSame('3' . self::NNBSP . '160', GermanNumber::format(3160.4));
        self::assertSame('1' . self::NNBSP . '234' . self::NNBSP . '567,890', GermanNumber::format(1234567.89, 3));
        self::assertSame('999', GermanNumber::format(999.0));
        self::assertSame("\u{2212}1" . self::NNBSP . '234,5', GermanNumber::format(-1234.5, 1));
        self::assertSame('0,0', GermanNumber::format(-0.04, 1));
    }

    /** @return array<string, array{string, ?float}> */
    public static function numbers(): array
    {
        return [
            'as the app writes it' => ['23702.0', 23702.0],
            'decimal comma' => ['23702,0', 23702.0],
            'space thousands' => ['23 702,0', 23702.0],
            'narrow space thousands' => ['23' . "\u{202F}" . '702,0', 23702.0],
            'dot thousands with comma' => ['23.702,5', 23702.5],
            'three decimals' => ['1234.567', 1234.567],
            'integer' => ['85', 85.0],
            'unicode minus' => ["\u{2212}3,5", -3.5],
            'empty' => ['', null],
            'text' => ['abgelesen', null],
            'misplaced dots' => ['2.37.02,0', null],
        ];
    }

    #[DataProvider('numbers')]
    public function testParseNumber(string $text, ?float $expected): void
    {
        self::assertSame($expected, Reading::parseNumber($text));
    }

    public function testAddReadingInsertsOneRowOnTop(): void
    {
        $original = Fixtures::read('objekte/efh-musterstrasse/zaehler/strom-haupt.md');

        $note = MeterNote::addReading(NoteDocument::parse($original), new Reading('2026-11-01', 23770.0), 'christian');

        $expected = str_replace(
            "|------------|---------|---------|------------------------------|-----------|\n",
            "|------------|---------|---------|------------------------------|-----------|\n| 2026-11-01 | 23770.0 | regulär |                              | christian |\n",
            $original,
        );
        $expected = str_replace("erstellt_von: christian\n---", "erstellt_von: christian\ngeaendert_von: christian\n---", $expected);
        self::assertSame($expected, $note->toString());
        self::assertSame(23770.0, Meter::fromNote($note)->latest()->value);
    }

    public function testOlderReadingGoesToItsPlace(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/zaehler/strom-haupt.md'));

        $note = MeterNote::addReading($note, new Reading('2026-09-15', 23340.0, 'Stichtag'), 'christian');

        $rows = $note->body()->table()->rows();
        self::assertSame(['2026-10-24', '2026-10-01', '2026-09-15', '2026-09-01', '2026-08-01'], array_column($rows, 'Datum'));
        self::assertSame('Stichtag', $rows[2]['Anlass']);
    }

    public function testDecimalsFollowTheMeter(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/zaehler/wasser-haupt.md'));

        $note = MeterNote::addReading($note, new Reading('2026-11-01', 1240.5), 'christian');

        self::assertSame('1240.500', $note->body()->table()->row(0)['Stand']);
    }

    public function testNoteWithoutTableGetsOne(): void
    {
        $note = NoteDocument::parse("---\ntyp: zaehler\nid: gas\nnachkommastellen: 3\n---\nZähler im Keller.\n");

        $note = MeterNote::addReading($note, new Reading('2026-10-24', 4711.25), 'christian');

        self::assertSame(
            "---\ntyp: zaehler\nid: gas\nnachkommastellen: 3\ngeaendert_von: christian\n---\n"
            . "| Datum      | Stand | Anlass  | Foto | Von |\n|------------|-------|---------|------|-----|\n"
            . "| 2026-10-24 | 4711.250 | regulär |      | christian |\n\nZähler im Keller.\n",
            $note->toString(),
        );
    }
}
