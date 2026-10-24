<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Checklist;
use Wohnpilot\Tests\Fixtures;

final class ChecklistTest extends TestCase
{
    private const MOVE_OUT = 'objekte/wohnung-altstadt/umzug/auszug.md';

    public function testReadsTasksByHeading(): void
    {
        $sections = Checklist::sections(Fixtures::read(self::MOVE_OUT));

        self::assertSame(['Vorher', 'Am Auszugstag', 'Danach'], array_column($sections, 'title'));
        self::assertSame(['index' => 0, 'text' => 'Mietvertrag kündigen (spätestens 2024-08-31)', 'done' => true], $sections[0]['items'][0]);
        self::assertSame(['index' => 9, 'text' => 'Kaution zurückfordern', 'done' => false], $sections[2]['items'][1]);
        self::assertSame(['done' => 9, 'total' => 11], Checklist::progress(Fixtures::read(self::MOVE_OUT)));
    }

    public function testTickChangesOnlyThatBox(): void
    {
        $original = Fixtures::read(self::MOVE_OUT);
        $ticked = Checklist::tick($original, 9, 'Kaution zurückfordern', true);

        self::assertSame(str_replace('- [ ] Kaution zurückfordern', '- [x] Kaution zurückfordern', $original), $ticked);
        self::assertSame($original, Checklist::tick($ticked, 9, 'Kaution zurückfordern', false));
        self::assertSame($ticked, Checklist::tick($ticked, 9, 'Kaution zurückfordern', true), 'twice is the same');
    }

    public function testRefusesWhenTheTaskChanged(): void
    {
        $original = Fixtures::read(self::MOVE_OUT);

        self::assertNull(Checklist::tick($original, 9, 'Kaution zurück', true));
        self::assertNull(Checklist::tick($original, 99, 'Kaution zurückfordern', true));
    }

    public function testOtherListStylesAndLineEndings(): void
    {
        $note = "---\r\ntyp: checkliste\r\n---\r\n* [X] Eins\r\n  + [ ] Zwei\r\n- Kein Haken\r\n";

        self::assertSame([['title' => null, 'items' => [['index' => 0, 'text' => 'Eins', 'done' => true], ['index' => 1, 'text' => 'Zwei', 'done' => false]]]], Checklist::sections($note));
        self::assertSame("---\r\ntyp: checkliste\r\n---\r\n* [X] Eins\r\n  + [x] Zwei\r\n- Kein Haken\r\n", Checklist::tick($note, 1, 'Zwei', true));
    }
}
