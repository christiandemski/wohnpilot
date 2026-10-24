<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** Parser checks for the stage 3 notes: maintenance, appointments, waste, defects and devices. */
final class HouseNotesTest extends TestCase
{
    private const OBJECT = 'objekte/efh-musterstrasse';
    private const RAUCHMELDER = self::OBJECT . '/wartung/rauchmelder.md';
    private const HECKE = self::OBJECT . '/wartung/heckenschnitt.md';
    private const HEIZUNG = self::OBJECT . '/wartung/heizungswartung.md';
    private const TERMIN = self::OBJECT . '/termine/schornsteinfeger-2026-11.md';
    private const MUELL = self::OBJECT . '/termine/muell.md';
    private const FENSTER = self::OBJECT . '/maengel/fenster-schlafzimmer.md';
    private const WASSERHAHN = self::OBJECT . '/maengel/wasserhahn-bad.md';
    private const WASCHMASCHINE = self::OBJECT . '/geraete/waschmaschine.md';
    private const GASTHERME = self::OBJECT . '/geraete/gastherme.md';

    public function testReadsMaintenanceWithHistory(): void
    {
        $note = NoteDocument::parse(Fixtures::read(self::RAUCHMELDER));
        $data = $note->frontmatter()->all();

        self::assertSame(['wartung', 'Rauchmelder testen', 12, 'mieter', 'flur', 14], [$data['typ'], $data['titel'], $data['intervall_monate'], $data['zustaendig'], $data['raum'], $data['vorlauf_tage']]);
        self::assertSame(['Datum', 'Erledigt von', 'Notiz'], $note->body()->table()->headers());
        self::assertSame(['Datum' => '2025-11-08', 'Erledigt von' => 'christian', 'Notiz' => 'Alle 3 Melder, Batterie Flur neu'], $note->body()->table()->row(0));
        self::assertStringContainsString('Prüftaste je Melder', $note->body()->text());
    }

    public function testReadsSeasonalAndNeverDoneMaintenance(): void
    {
        $hedge = NoteDocument::parse(Fixtures::read(self::HECKE));
        self::assertSame(['von' => 10, 'bis' => 2], $hedge->frontmatter()->get('saison'));
        self::assertSame('garten', $hedge->frontmatter()->get('pflicht'));
        self::assertSame(2, $hedge->body()->table()->count());
        self::assertSame('', $hedge->body()->table()->row(1)['Notiz']);

        $heating = NoteDocument::parse(Fixtures::read(self::HEIZUNG));
        self::assertNull($heating->body()->table(), 'never done: no history yet');
        self::assertSame('2026-11-01', $heating->frontmatter()->get('ab'));
        self::assertSame('vermieter', $heating->frontmatter()->get('zustaendig'));
        self::assertSame(['gastherme'], $heating->frontmatter()->get('bezieht_sich_auf'));
    }

    public function testMarkingMaintenanceDoneAddsOneLineOnTop(): void
    {
        $original = Fixtures::read(self::RAUCHMELDER);
        $note = NoteDocument::parse($original);

        HistoryTable::insertNewestFirst($note->body()->table(), 'Datum', ['Datum' => '2026-11-07', 'Erledigt von' => 'christian', 'Notiz' => '']);

        self::assertSame(
            str_replace("|------------|--------------|----------------------------------|\n", "|------------|--------------|----------------------------------|\n| 2026-11-07 | christian    |                                  |\n", $original),
            $note->toString(),
        );
    }

    public function testFirstDoneCreatesTheHistoryTable(): void
    {
        $note = HistoryTable::ensure(NoteDocument::parse(Fixtures::read(self::HEIZUNG)), ['Datum', 'Erledigt von', 'Notiz'], ['Datum' => '2026-11-20', 'Erledigt von' => 'vermieter', 'Notiz' => 'Heizungsbauer']);
        HistoryTable::insertNewestFirst($note->body()->table(), 'Datum', ['Datum' => '2026-11-20', 'Erledigt von' => 'vermieter', 'Notiz' => 'Heizungsbauer']);

        $read = NoteDocument::parse($note->toString());
        self::assertSame([['Datum' => '2026-11-20', 'Erledigt von' => 'vermieter', 'Notiz' => 'Heizungsbauer']], $read->body()->table()->rows());
        self::assertStringContainsString('Der Vermieter beauftragt', $read->body()->text(), 'text below stays');
    }

    public function testReadsAppointmentWithTimes(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::TERMIN))->frontmatter()->all();

        self::assertSame('termin', $data['typ']);
        self::assertSame('Schornsteinfeger – Kehren und Feuerstättenschau', $data['titel']);
        self::assertSame(['2026-11-12', '08:00', '09:00'], [$data['datum'], $data['uhrzeit'], $data['bis']]);
        self::assertSame('Heizungskeller und Kaminofen', $data['ort']);
    }

    public function testReadsWasteCalendarSettings(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::MUELL))->frontmatter()->all();

        self::assertTrue($data['vollservice']);
        self::assertSame('https://abfuhrkalender.example.org/musterstrasse.ics', $data['kalender_url']);
        self::assertSame(['rest', 'bio', 'papier', 'gelb'], array_column($data['tonnen'], 'id'));
        self::assertSame(['id' => 'gelb', 'name' => 'Gelbe Tonne', 'farbe' => 'bin-gelb', 'stichwort' => 'Gelb'], $data['tonnen'][3]);
    }

    public function testReadsDefectWithStatusHistoryAndPhoto(): void
    {
        $note = NoteDocument::parse(Fixtures::read(self::FENSTER));
        $table = $note->body()->table();

        self::assertSame(['Datum', 'Status', 'Kanal', 'Notiz'], $table->headers());
        self::assertSame(['bestaetigt', 'gemeldet', 'entdeckt'], array_column($table->rows(), 'Status'), 'newest first');
        self::assertSame('2026-11-02', $note->frontmatter()->get('nachfassen'));
        self::assertCount(1, $note->body()->tables(), 'the image line is text, not a table');
        self::assertStringContainsString('![Fenster Schlafzimmer, Rahmen unten links](../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg)', $note->body()->text());
    }

    public function testStatusChangeKeepsTextAndPhoto(): void
    {
        $original = Fixtures::read(self::FENSTER);
        $note = NoteDocument::parse($original);

        HistoryTable::insertNewestFirst($note->body()->table(), 'Datum', ['Datum' => '2026-10-20', 'Status' => 'termin', 'Kanal' => 'Telefon', 'Notiz' => 'Fensterbauer am 03.11.']);
        $note->frontmatter()->set('nachfassen', '2026-11-04');

        $read = NoteDocument::parse($note->toString());
        self::assertSame('termin', $read->body()->table()->row(0)['Status']);
        self::assertSame(4, $read->body()->table()->count());
        self::assertSame(NoteDocument::parse($original)->body()->text(), $read->body()->text(), 'text and photo untouched');
        self::assertSame(count(explode("\n", $original)) + 1, count(explode("\n", $note->toString())));
    }

    public function testReadsMinorRepair(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::WASSERHAHN))->frontmatter()->all();

        self::assertTrue($data['kleinreparatur']);
        self::assertSame(38.5, $data['kosten_eur']);
        self::assertSame('mieter', $data['zustaendig']);
    }

    public function testReadsDevices(): void
    {
        $washer = NoteDocument::parse(Fixtures::read(self::WASCHMASCHINE));
        $data = $washer->frontmatter()->all();
        self::assertSame(['[Hersteller]', '[Modell]', '[Seriennummer]'], [$data['hersteller'], $data['modell'], $data['seriennummer']]);
        self::assertSame(['mieter', '2024-11-20', 649, 36], [$data['eigentum'], $data['kaufdatum'], $data['preis_eur'], $data['garantie_monate']]);
        self::assertNull($washer->body()->table());

        $boiler = NoteDocument::parse(Fixtures::read(self::GASTHERME))->frontmatter()->all();
        self::assertSame(['vermieter', 2016], [$boiler['eigentum'], $boiler['baujahr']]);
        self::assertArrayNotHasKey('kaufdatum', $boiler);
    }

    public function testNewSeasonKeepsInlineMap(): void
    {
        $original = Fixtures::read(self::HECKE);
        $note = NoteDocument::parse($original);

        $note->frontmatter()->set('saison', ['von' => 11, 'bis' => 2]);

        self::assertSame(str_replace('saison: { von: 10, bis: 2 }', 'saison: { von: 11, bis: 2 }', $original), $note->toString());
    }

    public function testObjectHasYearlyLimitForMinorRepairs(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::OBJECT . '/_objekt.md'))->frontmatter()->all();

        self::assertSame([100, 300], [$data['kleinreparatur_grenze_eur'], $data['kleinreparatur_jahresgrenze_eur']]);
        self::assertSame(['garten', 'winterdienst'], $data['mieterpflichten']);
        self::assertSame(['gas', 'kamin'], $data['heizung']);
    }

    public function testWasteCalendarFixtureIsARealIcsFile(): void
    {
        $ics = file_get_contents(dirname(Fixtures::root()) . '/abfuhrkalender.ics');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringNotContainsString("\r\r", $ics);
        self::assertSame(substr_count($ics, "\n"), substr_count($ics, "\r\n"), 'CRLF line ends, kept by .gitattributes');
        self::assertStringContainsString("bereitstellen\\, sonst\r\n  kann", $ics, 'folded line');
    }
}
