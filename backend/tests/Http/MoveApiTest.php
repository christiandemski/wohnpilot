<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** Moving out, into a new home and the switch (today 2026-10-24). */
final class MoveApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function testHomesWithTheirState(): void
    {
        $status = self::decode($this->request('GET', '/api/move'));

        self::assertSame('efh-musterstrasse', $status['active']);
        self::assertSame(['efh-musterstrasse' => 'aktiv', 'wohnung-altstadt' => 'frueher'], array_column($status['homes'], 'state', 'id'));
        self::assertSame(
            [['id' => 'auszug-wohnung-altstadt', 'objekt' => 'wohnung-altstadt', 'art' => 'auszug', 'datum' => '2024-11-30', 'done' => 9, 'total' => 11]],
            $status['homes'][1]['checklists'],
        );
    }

    public function testMoveOutChecklistComesFromYourData(): void
    {
        $response = $this->request('POST', '/api/move/out', ['auszug' => '2027-03-31']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertStringContainsString("auszug: 2027-03-31\n", file_get_contents("$this->root/objekte/efh-musterstrasse/_objekt.md"));
        $note = file_get_contents("$this->root/objekte/efh-musterstrasse/umzug/auszug.md");
        self::assertStringStartsWith("---\ntyp: checkliste\nid: auszug-efh-musterstrasse\nobjekt: efh-musterstrasse\nart: auszug\ndatum: 2027-03-31\nerstellt_von: christian\n---\n## Vorher\n\n", $note);
        foreach ([
            // 3 months: in by the 3rd working day of January
            '- [ ] Mietvertrag kündigen, schriftlich und von allen unterschrieben (muss bis 05.01.2027 beim Vermieter sein)',
            '- [ ] Stadtwerke: Umzug melden oder zum 31.03.2027 kündigen',
            '- [ ] [Versicherer]: neue Adresse melden',
            '- [ ] Offenen Mangel klären: Fenster im Schlafzimmer zieht',
            "## Am Auszugstag\n\n- [ ] Endstand Strom ablesen, mit Foto\n- [ ] Endstand Wasser ablesen, mit Foto\n- [ ] Gastherme bleibt da: Zustand fotografieren\n",
            '- [ ] Kaution zurückfordern (4.350 €)',
            '- [ ] Letzte Nebenkostenabrechnung prüfen (der Vermieter hat dafür bis 31.12.2028 Zeit)',
        ] as $line) {
            self::assertStringContainsString($line, $note);
        }

        // A later date keeps the list and its ticks
        $checklist = self::decode($this->request('GET', '/api/checklists/auszug-efh-musterstrasse'));
        $first = $checklist['sections'][0]['items'][0];
        self::assertSame(200, $this->request('PUT', '/api/checklists/auszug-efh-musterstrasse/items/0', ['text' => $first['text'], 'done' => true])->getStatusCode());
        $this->request('POST', '/api/move/out', ['auszug' => '2027-04-30']);
        self::assertStringContainsString('- [x] Mietvertrag kündigen', file_get_contents("$this->root/objekte/efh-musterstrasse/umzug/auszug.md"));
        self::assertStringContainsString("auszug: 2027-04-30\n", file_get_contents("$this->root/objekte/efh-musterstrasse/_objekt.md"));

        self::assertSame(['auszug' => 'before_move_in'], self::decode($this->request('POST', '/api/move/out', ['auszug' => '2020-01-31']))['fields']);
    }

    public function testTickingAChecklist(): void
    {
        $id = 'auszug-wohnung-altstadt';
        $done = self::decode($this->request('PUT', "/api/checklists/$id/items/9", ['text' => 'Kaution zurückfordern', 'done' => true]));
        self::assertSame(10, $done['done']);
        self::assertSame('wohnung', $done['home']['art']);
        $file = file_get_contents("$this->root/objekte/wohnung-altstadt/umzug/auszug.md");
        self::assertStringContainsString("- [x] Kaution zurückfordern\n", $file);
        self::assertStringContainsString("geaendert_von: christian\n", $file);

        // Changed in the Notes app meanwhile: nothing is ticked
        $conflict = $this->request('PUT', "/api/checklists/$id/items/10", ['text' => 'Etwas anderes', 'done' => true]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(10, self::decode($conflict)['checklist']['done']);

        // Members tick off too
        $this->signIn('alex');
        self::assertSame(200, $this->request('PUT', "/api/checklists/$id/items/9", ['text' => 'Kaution zurückfordern', 'done' => false])->getStatusCode());
    }

    public function testNewHomeAndTheSwitch(): void
    {
        $created = $this->request('POST', '/api/move/in', ['art' => 'wohnung', 'bezeichnung' => 'Wohnung am Park', 'adresse' => 'Parkweg 2, 12345 Ort', 'einzug' => '2027-04-01', 'raeume' => ['Küche', 'Bad']]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('wohnung-am-park', self::decode($created)['created']);
        self::assertSame(['efh-musterstrasse' => 'aktiv', 'wohnung-am-park' => 'geplant', 'wohnung-altstadt' => 'frueher'], array_column(self::decode($created)['homes'], 'state', 'id'));
        self::assertStringContainsString("aktives_objekt: efh-musterstrasse\n", file_get_contents("$this->root/_haushalt.md"));
        $moveIn = file_get_contents("$this->root/objekte/wohnung-am-park/umzug/einzug.md");
        self::assertStringContainsString("art: einzug\ndatum: 2027-04-01\n", $moveIn);
        self::assertStringContainsString('- [ ] Stadtwerke: auf die neue Adresse umstellen', $moveIn);

        self::assertSame(['einzug' => 'required'], self::decode($this->request('POST', '/api/move/in', ['art' => 'wohnung', 'bezeichnung' => 'X']))['fields']);

        $switched = self::decode($this->request('POST', '/api/move/switch', ['objekt' => 'wohnung-am-park']));
        self::assertSame('wohnung-am-park', $switched['active']);
        self::assertSame(['wohnung-am-park' => 'aktiv', 'efh-musterstrasse' => 'frueher', 'wohnung-altstadt' => 'frueher'], array_column($switched['homes'], 'state', 'id'));
        // Without a planned move-out date: the day before moving in
        self::assertStringContainsString("auszug: 2027-03-31\n", file_get_contents("$this->root/objekte/efh-musterstrasse/_objekt.md"));
        // The lists now show the new home; the old one is kept, not deleted
        self::assertSame([], self::decode($this->request('GET', '/api/meters'))['meters']);
        self::assertFileExists("$this->root/objekte/efh-musterstrasse/zaehler/strom-haupt.md");

        self::assertSame('archived', self::decode($this->request('POST', '/api/move/switch', ['objekt' => 'efh-musterstrasse']))['error']);
        self::assertSame('already_active', self::decode($this->request('POST', '/api/move/switch', ['objekt' => 'wohnung-am-park']))['error']);
    }

    public function testOnlyAdministratorsMove(): void
    {
        $this->signIn('alex');
        self::assertSame(403, $this->request('POST', '/api/move/out', ['auszug' => '2027-03-31'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/move/switch', ['objekt' => 'wohnung-altstadt'])->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/move')->getStatusCode());
    }
}
