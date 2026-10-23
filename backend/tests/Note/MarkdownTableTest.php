<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class MarkdownTableTest extends TestCase
{
    private const STROM = 'objekte/efh-musterstrasse/zaehler/strom-haupt.md';

    public function testReadsRowsByHeader(): void
    {
        $table = NoteDocument::parse(Fixtures::read(self::STROM))->body()->table();

        self::assertSame(['Datum', 'Stand', 'Anlass', 'Foto', 'Von'], $table->headers());
        self::assertSame(4, $table->count());
        self::assertSame(
            ['Datum' => '2026-10-24', 'Stand' => '23702.0', 'Anlass' => 'regulär', 'Foto' => 'anhaenge/strom-2026-10-24.jpg', 'Von' => 'christian'],
            $table->row(0),
        );
        self::assertSame('', $table->row(1)['Foto']);
    }

    public function testInsertingAtTopAddsExactlyOneAlignedLine(): void
    {
        $original = Fixtures::read(self::STROM);
        $note = NoteDocument::parse($original);

        $note->body()->table()->insertRow(['Datum' => '2026-11-01', 'Stand' => '23950.0', 'Anlass' => 'regulär', 'Von' => 'christian']);

        $old = explode("\n", $original);
        $new = explode("\n", $note->toString());
        $index = array_search('|------------|---------|---------|------------------------------|-----------|', $old, true);
        self::assertSame('| 2026-11-01 | 23950.0 | regulär |                              | christian |', $new[$index + 1]);
        array_splice($new, $index + 1, 1);
        self::assertSame($old, $new);
    }

    public function testFreeTextAfterTableIsKept(): void
    {
        $note = NoteDocument::parse(Fixtures::read(self::STROM));

        $note->body()->table()->insertRow(['Datum' => '2026-11-01', 'Stand' => '1']);

        self::assertStringEndsWith("| christian |\n\nZähler hängt im Keller links neben der Tür.\n", $note->toString());
        self::assertSame("\nZähler hängt im Keller links neben der Tür.\n", $note->body()->text());
    }

    public function testTableAtEndOfFileWithoutNewline(): void
    {
        $original = "---\ntyp: zaehler\nid: a\n---\n| Datum | Stand |\n|---|---|\n| 2026-10-01 | 5 |";
        $note = NoteDocument::parse($original);
        self::assertSame($original, $note->toString());

        $note->body()->table()->insertRow(['Datum' => '2026-09-01', 'Stand' => '4'], 1);

        self::assertSame(
            "---\ntyp: zaehler\nid: a\n---\n| Datum | Stand |\n|---|---|\n| 2026-10-01 | 5 |\n| 2026-09-01 | 4 |",
            $note->toString(),
        );
    }

    public function testEmptyTableGetsFirstRow(): void
    {
        $note = NoteDocument::parse("| Datum      | Stand |\n|------------|-------|\n\nNotiz\n");

        $note->body()->table()->insertRow(['Datum' => '2026-10-24', 'Stand' => '12']);

        self::assertSame("| Datum      | Stand |\n|------------|-------|\n| 2026-10-24 | 12    |\n\nNotiz\n", $note->toString());
    }

    public function testCrlfIsPreserved(): void
    {
        $original = "---\r\ntyp: zaehler\r\nid: a\r\n---\r\n| Datum | Stand |\r\n|-------|-------|\r\n| x     | 1     |\r\n";
        $note = NoteDocument::parse($original);
        self::assertSame($original, $note->toString());

        $note->body()->table()->insertRow(['Datum' => 'y', 'Stand' => '2']);
        $note->frontmatter()->set('raum', 'keller');

        self::assertSame(
            "---\r\ntyp: zaehler\r\nid: a\r\nraum: keller\r\n---\r\n| Datum | Stand |\r\n|-------|-------|\r\n| y     | 2     |\r\n| x     | 1     |\r\n",
            $note->toString(),
        );
    }

    public function testPipesInCellsAreEscaped(): void
    {
        $note = NoteDocument::parse("| A | B |\n|---|---|\n");

        $note->body()->table()->insertRow(['A' => 'links | rechts', 'B' => "zwei\nZeilen"]);

        self::assertSame("| links \\| rechts | zwei Zeilen |\n", explode("|---|---|\n", $note->toString())[1]);
        self::assertSame('links | rechts', $note->body()->table()->row(0)['A']);
    }

    public function testUnknownColumnIsRejected(): void
    {
        $table = NoteDocument::parse("| A |\n|---|\n")->body()->table();

        $this->expectException(InvalidArgumentException::class);
        $table->insertRow(['B' => 'x']);
    }

    public function testReplaceAndRemoveRow(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/zaehler/wasser-haupt.md'));
        $table = $note->body()->table();

        $table->replaceRow(0, ['Stand' => '1234.600']);
        self::assertStringContainsString("| 2026-10-01 | 1234.600 | regulär |      | christian |\n", $note->toString());

        $table->removeRow(0);
        self::assertStringEndsWith("|------------|----------|---------|------|-----------|\n", $note->toString());
    }

    public function testNoteWithoutFrontmatterOrTable(): void
    {
        $note = NoteDocument::parse("Nur Text\n| kein Tisch\n");

        self::assertFalse($note->hasFrontmatter());
        self::assertNull($note->body()->table());
        self::assertSame("Nur Text\n| kein Tisch\n", $note->toString());

        $note->frontmatter()->set('typ', 'wissen');
        self::assertSame("---\ntyp: wissen\n---\nNur Text\n| kein Tisch\n", $note->toString());
    }

    public function testDashesInTextAreNotATableSeparator(): void
    {
        $original = "---\ntyp: wissen\nid: a\n---\nTitel\n---\n\n| Nicht | Tabelle\nweiter\n";

        self::assertSame($original, NoteDocument::parse($original)->toString());
        self::assertSame([], NoteDocument::parse($original)->body()->tables());
    }
}
