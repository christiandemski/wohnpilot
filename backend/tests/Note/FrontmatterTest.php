<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class FrontmatterTest extends TestCase
{
    private const STROM = 'objekte/efh-musterstrasse/zaehler/strom-haupt.md';

    public function testReadsScalarsAndFlowMaps(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::STROM))->frontmatter()->all();

        self::assertSame('zaehler', $data['typ']);
        self::assertSame('1ESY1160123456', $data['zaehlernummer']);
        self::assertSame(5, $data['stellen']);
        self::assertSame(['arbeitspreis_ct' => 29.8, 'grundpreis_eur_monat' => 12.5, 'abschlag_eur' => 85], $data['tarif']);
    }

    public function testDatesStayIsoStrings(): void
    {
        $objekt = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/_objekt.md'))->frontmatter();
        $muell = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/termine/muell.md'))->frontmatter();

        self::assertSame('2024-12-01', $objekt->get('einzug'));
        self::assertSame('2026-10-27', $muell->get('tonnen')[3]['naechste']);
    }

    public function testReadsQuotedPlaceholdersUnicodeAndLists(): void
    {
        $wasser = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/zaehler/wasser-haupt.md'))->frontmatter();
        $objekt = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/_objekt.md'))->frontmatter();
        $haushalt = NoteDocument::parse(Fixtures::read('_haushalt.md'))->frontmatter();

        self::assertSame('[Nummer]', $wasser->get('zaehlernummer'));
        self::assertSame('m³', $wasser->get('einheit'));
        self::assertSame(['gas', 'kamin'], $objekt->get('heizung'));
        self::assertSame([['id' => 'christian', 'name' => 'Christian', 'rolle' => 'verwalter']], $haushalt->get('personen'));
        self::assertSame(1, $haushalt->get('einstellungen')['ablesetag']);
    }

    public function testChangingOneKeyChangesExactlyOneLine(): void
    {
        $original = Fixtures::read(self::STROM);
        $note = NoteDocument::parse($original);

        $note->frontmatter()->set('raum', 'hauswirtschaftsraum');

        $diff = self::changedLines($original, $note->toString());
        self::assertSame([['raum: keller', 'raum: hauswirtschaftsraum']], $diff);
    }

    public function testChangingFlowMapKeepsInlineStyle(): void
    {
        $original = Fixtures::read(self::STROM);
        $note = NoteDocument::parse($original);
        $tarif = $note->frontmatter()->get('tarif');
        $tarif['abschlag_eur'] = 91;

        $note->frontmatter()->set('tarif', $tarif);

        self::assertSame(
            [[
                'tarif: { arbeitspreis_ct: 29.8, grundpreis_eur_monat: 12.5, abschlag_eur: 85 }',
                'tarif: { arbeitspreis_ct: 29.8, grundpreis_eur_monat: 12.5, abschlag_eur: 91 }',
            ]],
            self::changedLines($original, $note->toString()),
        );
    }

    public function testNewKeyIsAppendedAndUnknownKeysSurvive(): void
    {
        $original = "---\ntyp: zaehler\nid: x\n# Kommentar aus der Notes-App\nfarbe_im_keller: rot\n---\nText\n";
        $note = NoteDocument::parse($original);

        $note->frontmatter()->set('geaendert_von', 'christian');

        self::assertSame(
            "---\ntyp: zaehler\nid: x\n# Kommentar aus der Notes-App\nfarbe_im_keller: rot\ngeaendert_von: christian\n---\nText\n",
            $note->toString(),
        );
        self::assertSame('rot', $note->frontmatter()->get('farbe_im_keller'));
    }

    public function testReplacingABlockKeyReplacesItsWholeBlock(): void
    {
        $original = Fixtures::read('_haushalt.md');
        $note = NoteDocument::parse($original);

        $note->frontmatter()->set('personen', [['id' => 'christian', 'name' => 'Christian', 'rolle' => 'verwalter'], ['id' => 'anna', 'name' => 'Anna', 'rolle' => 'mitglied']]);

        $text = $note->toString();
        self::assertStringContainsString("personen: [{ id: christian, name: Christian, rolle: verwalter }, { id: anna, name: Anna, rolle: mitglied }]\naktives_objekt: efh-musterstrasse\n", $text);
        self::assertSame('anna', NoteDocument::parse($text)->frontmatter()->get('personen')[1]['id']);
        self::assertStringContainsString("einstellungen:\n  ablesetag: 1\n", $text);
    }

    public function testRemoveDropsTheWholeBlock(): void
    {
        $note = NoteDocument::parse(Fixtures::read('_haushalt.md'));

        $note->frontmatter()->remove('einstellungen');

        self::assertStringEndsWith("speicher: nextcloud\n---\nHaushalt mit allen Personen und Einstellungen.\n", $note->toString());
        self::assertFalse($note->frontmatter()->has('einstellungen'));
    }

    public function testWrittenValuesReadBackTheSame(): void
    {
        $note = NoteDocument::parse("---\ntyp: test\n---\n");
        $values = [
            'datum' => '2026-10-24',
            'text' => 'Keller: links',
            'zahl' => 12.5,
            'ganz' => 3.0,
            'leer' => null,
            'ja' => true,
            'liste' => ['a b', 'c'],
            'platzhalter' => '[Nummer]',
        ];
        foreach ($values as $key => $value) {
            $note->frontmatter()->set($key, $value);
        }

        $reread = NoteDocument::parse($note->toString())->frontmatter();
        foreach ($values as $key => $value) {
            self::assertSame($value, $reread->get($key), $key);
        }
        self::assertStringContainsString("datum: 2026-10-24\n", $note->toString());
    }

    public function testCreateWritesFrontmatterAndBody(): void
    {
        $note = NoteDocument::create(['typ' => 'zaehler', 'id' => 'gas', 'einzug' => '2024-12-01'], "Text\n");

        self::assertSame("---\ntyp: zaehler\nid: gas\neinzug: 2024-12-01\n---\nText\n", $note->toString());
    }

    /** @return list<array{string, string}> pairs of [old, new] for lines that differ, same line count required */
    private static function changedLines(string $old, string $new): array
    {
        $a = explode("\n", $old);
        $b = explode("\n", $new);
        self::assertCount(count($a), $b, 'line count changed');
        $diff = [];
        foreach ($a as $i => $line) {
            if ($line !== $b[$i]) {
                $diff[] = [$line, $b[$i]];
            }
        }
        return $diff;
    }
}
