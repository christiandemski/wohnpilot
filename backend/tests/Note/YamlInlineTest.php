<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Note\YamlInline;

final class YamlInlineTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function values(): array
    {
        return [
            'words stay plain' => ['Zählerstand Strom an die Stadtwerke melden', 'Zählerstand Strom an die Stadtwerke melden'],
            'number with space' => ['1ESY 123', '1ESY 123'],
            'colon without space' => ['Termin 12:30', 'Termin 12:30'],
            'time of day' => ['08:00', '08:00'],
            'hash inside a word' => ['C#-Kurs', 'C#-Kurs'],
            'iso date' => ['2026-10-24', '2026-10-24'],
            'placeholder in brackets' => ['[Nummer]', '"[Nummer]"'],
            'comma' => ['[Bank], Mietkautionskonto', '"[Bank], Mietkautionskonto"'],
            'key indicator' => ['Keller: links', '"Keller: links"'],
            'comment' => ['Zähler #2', '"Zähler #2"'],
            'list dash' => ['- Punkt', '"- Punkt"'],
            'reads as bool' => ['true', '"true"'],
            'yaml 1.1 bool' => ['ja', 'ja'],
            'yes' => ['yes', '"yes"'],
            'reads as number' => ['4711', '"4711"'],
            'reads as null' => ['null', '"null"'],
            'leading space' => [' x', '" x"'],
            'quote inside' => ['Er sagte "ok"', '"Er sagte \"ok\""'],
            'empty' => ['', '""'],
            'map with text' => [['art' => 'kautionskonto', 'hinterlegt' => 'Bank, Konto'], '{ art: kautionskonto, hinterlegt: "Bank, Konto" }'],
            'list of ids' => [['strom-haupt', 'wasser-haupt'], '[strom-haupt, wasser-haupt]'],
            'number' => [4350, '4350'],
            'float' => [96.4, '96.4'],
        ];
    }

    #[DataProvider('values')]
    public function testFormatsAndReadsBack(mixed $value, string $expected): void
    {
        self::assertSame($expected, YamlInline::format($value));
        self::assertSame($value, NoteDocument::parse("---\nv: $expected\n---\n")->frontmatter()->get('v'), 'reads back the same in a note');
    }
}
