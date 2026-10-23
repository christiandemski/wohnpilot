<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use Wohnpilot\Domain\Meter;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class MeterFactory
{
    public static function strom(): Meter
    {
        return Meter::fromNote(NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/zaehler/strom-haupt.md')));
    }

    /**
     * A meter from rows written newest first, like in a note.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $rows [date, value, occasion]
     */
    public static function withRows(array $rows, string $extraFrontmatter = ''): Meter
    {
        $text = "---\ntyp: zaehler\nid: test\neinheit: kWh\nnachkommastellen: 1\n$extraFrontmatter---\n| Datum | Stand | Anlass | Foto | Von |\n|---|---|---|---|---|\n";
        foreach ($rows as $row) {
            $text .= "| {$row[0]} | {$row[1]} | " . ($row[2] ?? 'regulär') . " |  | christian |\n";
        }
        return Meter::fromNote(NoteDocument::parse($text));
    }
}
