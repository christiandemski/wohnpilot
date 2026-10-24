<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Note\NoteImages;
use Wohnpilot\Tests\Fixtures;

/** Parser checks for the stage 4 notes: contacts, knowledge, emergency sheet and links to contacts. */
final class KnowledgeNotesTest extends TestCase
{
    private const WISSEN = 'objekte/efh-musterstrasse/wissen';

    public function testReadsContactsWithRoleAndEmergencies(): void
    {
        $landlord = NoteDocument::parse(Fixtures::read('kontakte/vermieter.md'))->frontmatter();
        self::assertSame(['kontakt', 'vermieter', 'efh-musterstrasse', '[Name Vermieter]'], [$landlord->get('typ'), $landlord->get('rolle'), $landlord->get('objekt'), $landlord->get('name')]);
        self::assertSame(['wasser', 'heizung'], $landlord->get('notfall'));

        $plumber = NoteDocument::parse(Fixtures::read('kontakte/heizungsbau-mueller.md'))->frontmatter();
        self::assertSame(['handwerker', 'heizung', 'Heizungsbau Müller GmbH'], [$plumber->get('rolle'), $plumber->get('gewerk'), $plumber->get('firma')]);
        self::assertNull($plumber->get('objekt'), 'a craftsman belongs to the household, not to one home');
    }

    public function testPhoneNumbersStayAsWritten(): void
    {
        $landlord = NoteDocument::parse(Fixtures::read('kontakte/vermieter.md'))->frontmatter();
        self::assertSame(491701234567, $landlord->get('mobil'), 'YAML makes a number of it …');
        self::assertSame('+491701234567', $landlord->raw('mobil'), '… the line still has the plus');
        self::assertSame('[Telefon]', $landlord->raw('telefon'), 'quoted values come back unquoted');

        $utility = NoteDocument::parse(Fixtures::read('kontakte/stadtwerke-stoerung.md'))->frontmatter();
        self::assertSame('08001234', $utility->raw('telefon'));
        self::assertSame('0800 1234567', NoteDocument::parse(Fixtures::read('kontakte/heizungsbau-mueller.md'))->frontmatter()->raw('telefon'));
        self::assertNull($utility->raw('fehlt'));
        self::assertNull($utility->raw('notfall'), 'a list is no single value');
    }

    public function testRawIgnoresCommentsAndOtherKeys(): void
    {
        $fm = NoteDocument::parse("---\ntelefon: 112   # Notruf\ntelefon_privat: 0301234\n---\n")->frontmatter();

        self::assertSame('112', $fm->raw('telefon'));
        self::assertSame('0301234', $fm->raw('telefon_privat'));
    }

    public function testKnowledgePageWithTextAndTable(): void
    {
        $note = NoteDocument::parse(Fixtures::read(self::WISSEN . '/sicherungskasten.md'));

        self::assertSame(['wissen', 'ort', 'keller', ['strom']], [$note->frontmatter()->get('typ'), $note->frontmatter()->get('kategorie'), $note->frontmatter()->get('raum'), $note->frontmatter()->get('notfall')]);
        self::assertStringStartsWith('Im Keller rechts neben der Tür', $note->body()->text());
        self::assertSame(['Sicherung', 'Raum', 'Hinweis'], $note->body()->table()->headers());
        self::assertSame(['Sicherung' => 'F2', 'Raum' => 'Küche', 'Hinweis' => 'Herd, eigener Kreis'], $note->body()->table()->row(1));
    }

    public function testKnowledgeWithPhotoAndSteps(): void
    {
        $tap = NoteDocument::parse(Fixtures::read(self::WISSEN . '/haupthahn.md'));
        self::assertSame(['anhaenge/wissen/haupthahn/2026-10-24-1.jpg'], array_column(NoteImages::in($tap->body()->text(), self::WISSEN), 'path'));

        $heating = NoteDocument::parse(Fixtures::read(self::WISSEN . '/heizung-umstellen.md'));
        self::assertSame(['bedienung', ['gastherme']], [$heating->frontmatter()->get('kategorie'), $heating->frontmatter()->get('bezieht_sich_auf')]);
        self::assertStringContainsString("2. Warmwasser bleibt an", $heating->body()->text());

        $emergency = NoteDocument::parse(Fixtures::read(self::WISSEN . '/notfall-wasser.md'))->frontmatter();
        self::assertSame(['notfall', 'wasser'], [$emergency->get('kategorie'), $emergency->get('szenario')]);
    }

    public function testEntriesLinkTheirContact(): void
    {
        $links = [];
        foreach (['objekte/efh-musterstrasse/maengel/fenster-schlafzimmer.md', 'objekte/efh-musterstrasse/wartung/heizungswartung.md', 'objekte/efh-musterstrasse/mietvertrag/mietvertrag.md', 'vertraege/strom-stadtwerke.md'] as $path) {
            $links[$path] = NoteDocument::parse(Fixtures::read($path))->frontmatter()->get('kontakt');
        }

        self::assertSame(['vermieter', 'heizungsbau-mueller', 'vermieter', 'stadtwerke-stoerung'], array_values($links));
    }
}
