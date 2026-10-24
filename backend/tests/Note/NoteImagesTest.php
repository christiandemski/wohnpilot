<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Note\NoteImages;
use Wohnpilot\Tests\Fixtures;

final class NoteImagesTest extends TestCase
{
    private const DEFECTS = 'objekte/efh-musterstrasse/maengel';
    private const DEVICES = 'objekte/efh-musterstrasse/geraete';

    public function testFindsImagesRelativeToTheNote(): void
    {
        $text = NoteDocument::parse(Fixtures::read(self::DEFECTS . '/fenster-schlafzimmer.md'))->body()->text();

        self::assertSame(
            [['alt' => 'Fenster Schlafzimmer, Rahmen unten links', 'src' => '../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg', 'path' => 'anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg']],
            NoteImages::in($text, self::DEFECTS),
        );
    }

    public function testSkipsUrlsAndPathsOutsideTheRoot(): void
    {
        $text = "![a](https://example.org/a.jpg)\n![b](/abs/b.jpg)\n![c](../../../../c.jpg)\n![Notes-App](.attachments.12/Foto%20Bad.png \"Bad\")\n";

        self::assertSame(
            ['objekte/efh-musterstrasse/maengel/.attachments.12/Foto Bad.png'],
            array_column(NoteImages::in($text, self::DEFECTS), 'path'),
        );
    }

    public function testRelativePathFromTheNote(): void
    {
        self::assertSame('../../../anhaenge/x.jpg', NoteImages::relative(self::DEFECTS, 'anhaenge/x.jpg'));
        self::assertSame('anhaenge/x.jpg', NoteImages::relative('', 'anhaenge/x.jpg'));
        self::assertSame('anhaenge/x.jpg', NoteImages::resolve(self::DEFECTS, NoteImages::relative(self::DEFECTS, 'anhaenge/x.jpg')));
    }

    public function testAppendAfterTextOrImage(): void
    {
        $boiler = Fixtures::read(self::DEVICES . '/gastherme.md');
        self::assertSame($boiler . "\n![Typenschild](../../../anhaenge/geraete/gastherme/2026-10-24-1.jpg)\n", NoteImages::append(NoteDocument::parse($boiler), 'Typenschild', '../../../anhaenge/geraete/gastherme/2026-10-24-1.jpg')->toString());

        $washer = Fixtures::read(self::DEVICES . '/waschmaschine.md');
        self::assertSame($washer . "![Rechnung](r.jpg)\n", NoteImages::append(NoteDocument::parse($washer), 'Rechnung', 'r.jpg')->toString(), 'images stay together');

        self::assertSame("---\nid: x\n---\n![a b](p.jpg)\n", NoteImages::append(NoteDocument::parse("---\nid: x\n---"), '[a]] b', 'p.jpg')->toString());
    }

    public function testRemoveLeavesTheRestAlone(): void
    {
        $original = Fixtures::read(self::DEFECTS . '/fenster-schlafzimmer.md');

        $note = NoteImages::remove(NoteDocument::parse($original), 'anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg', self::DEFECTS);

        self::assertSame(str_replace("\n![Fenster Schlafzimmer, Rahmen unten links](../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg)\n", '', $original), $note->toString());
        self::assertSame($original, NoteImages::remove(NoteDocument::parse($original), 'anhaenge/anders.jpg', self::DEFECTS)->toString());
    }
}
