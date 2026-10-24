<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** Maintenance, appointments, defects, devices, waste and settings through the API (today 2026-10-24). */
final class HouseApiTest extends ApiTestCase
{
    private const OBJECT = 'objekte/efh-musterstrasse';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function note(string $path): string
    {
        return file_get_contents("$this->root/$path");
    }

    public function testMaintenanceByDueDate(): void
    {
        $tasks = self::decode($this->request('GET', '/api/maintenance'))['tasks'];

        self::assertSame(
            [['heizungswartung', '2026-11-01', 'soon'], ['rauchmelder', '2026-11-08', 'ok'], ['heckenschnitt', '2027-02-28', 'ok']],
            array_map(static fn (array $t): array => [$t['id'], $t['evaluation']['dueDate'], $t['evaluation']['status']], $tasks),
        );
        $hedge = self::decode($this->request('GET', '/api/maintenance/heckenschnitt'));
        self::assertSame(['2026-02-14', 'christian', 'Thuja an der Straße'], array_values($hedge['history'][0]));
        self::assertStringStartsWith('Starker Rückschnitt', $hedge['notes']);
    }

    public function testDoneAndTakenBack(): void
    {
        $done = self::decode($this->request('POST', '/api/maintenance/rauchmelder/done', ['notiz' => 'Alle drei piepen']));
        self::assertSame(['2026-10-24', '2027-10-24'], [$done['evaluation']['lastDone'], $done['evaluation']['dueDate']]);
        self::assertSame(['date' => '2026-10-24', 'by' => 'christian', 'note' => 'Alle drei piepen'], $done['history'][0]);

        $this->request('POST', '/api/maintenance/rauchmelder/done', []);
        self::assertCount(2, self::decode($this->request('GET', '/api/maintenance/rauchmelder'))['history'], 'same day twice: one row');

        $back = self::decode($this->request('DELETE', '/api/maintenance/rauchmelder/done/2026-10-24'));
        self::assertSame('2026-11-08', $back['evaluation']['dueDate']);
        self::assertSame(422, $this->request('POST', '/api/maintenance/rauchmelder/done', ['datum' => '2026-12-01'])->getStatusCode(), 'not in the future');
    }

    public function testFirstDoneCreatesHistory(): void
    {
        $done = self::decode($this->request('POST', '/api/maintenance/heizungswartung/done', ['notiz' => 'Heizungsbauer']));

        self::assertSame('2027-10-24', $done['evaluation']['dueDate']);
        self::assertStringContainsString("| Datum      | Erledigt von | Notiz         |\n", $this->note(self::OBJECT . '/wartung/heizungswartung.md'));
    }

    public function testSuggestionsBecomeTasks(): void
    {
        $ids = array_column(self::decode($this->request('GET', '/api/maintenance/suggestions'))['suggestions'], 'id');
        self::assertContains('kamin-kehren', $ids);
        self::assertNotContains('heckenschnitt', $ids, 'exists already');

        $created = $this->request('POST', '/api/maintenance', ['vorschlag' => 'kamin-kehren']);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame(['kamin-kehren', 'Schornsteinfeger: Kaminofen kehren', 'dienstleister'], [self::decode($created)['id'], self::decode($created)['title'], self::decode($created)['fields']['zustaendig']]);
        self::assertStringContainsString("titel: \"Schornsteinfeger: Kaminofen kehren\"\n", $this->note(self::OBJECT . '/wartung/kamin-kehren.md'));
        self::assertNotContains('kamin-kehren', array_column(self::decode($this->request('GET', '/api/maintenance/suggestions'))['suggestions'], 'id'));

        $winter = self::decode($this->request('POST', '/api/maintenance', ['vorschlag' => 'winterdienst-ausruestung']));
        self::assertSame(['von' => 10, 'bis' => 11], $winter['fields']['saison']);
    }

    public function testOwnTaskNeedsOneInterval(): void
    {
        self::assertSame(['intervall_monate' => 'required'], self::decode($this->request('POST', '/api/maintenance', ['titel' => 'Filter']))['fields']);
        self::assertSame(['intervall_wochen' => 'one_of'], self::decode($this->request('POST', '/api/maintenance', ['titel' => 'Filter', 'intervall_monate' => 3, 'intervall_wochen' => 2]))['fields']);
        self::assertSame(['saison' => 'season'], self::decode($this->request('POST', '/api/maintenance', ['titel' => 'Filter', 'intervall_monate' => 3, 'saison' => ['von' => 13, 'bis' => 1]]))['fields']);

        $own = self::decode($this->request('POST', '/api/maintenance', ['titel' => 'Dunstabzug: Filter wechseln', 'intervall_monate' => '3', 'raum' => 'kueche']));
        self::assertSame(['dunstabzug-filter-wechseln', '2026-10-24', 'due'], [$own['id'], $own['evaluation']['dueDate'], $own['evaluation']['status']]);
    }

    public function testSmokeDetectorsCheckedByRadio(): void
    {
        $task = self::decode($this->request('GET', '/api/maintenance/rauchmelder'));
        $saved = self::decode($this->request('PUT', '/api/maintenance/rauchmelder', ['etag' => $task['etag'], 'changes' => ['ausfuehrung' => 'funk'], 'original' => []]));

        self::assertSame(['funk', true, 'ok'], [$saved['fields']['ausfuehrung'], $saved['evaluation']['automatic'], $saved['evaluation']['status']]);
        self::assertSame('rauchmelder', end(self::decode($this->request('GET', '/api/maintenance'))['tasks'])['id'], 'nothing to do: last in the list');
        self::assertSame(['ausfuehrung' => 'unknown'], self::decode($this->request('PUT', '/api/maintenance/rauchmelder', ['etag' => $saved['etag'], 'changes' => ['ausfuehrung' => 'magie'], 'original' => []]))['fields']);
    }

    public function testAppointments(): void
    {
        $list = self::decode($this->request('GET', '/api/appointments'));
        self::assertSame([['schornsteinfeger-2026-11', '2026-11-12', '08:00', 19]], array_map(static fn (array $a): array => [$a['id'], $a['date'], $a['time'], $a['daysUntil']], $list['upcoming']));

        self::assertSame(['bis' => 'after_start'], self::decode($this->request('POST', '/api/appointments', ['titel' => 'Handwerker', 'datum' => '2026-11-03', 'uhrzeit' => '9:00', 'bis' => '8:00']))['fields']);
        $created = self::decode($this->request('POST', '/api/appointments', ['titel' => 'Fensterbauer', 'datum' => '2026-11-03', 'uhrzeit' => '9:00', 'ort' => 'Schlafzimmer']));
        self::assertSame(['fensterbauer-2026-11', '09:00'], [$created['id'], $created['fields']['uhrzeit']]);
        self::assertSame(['fensterbauer-2026-11', 'schornsteinfeger-2026-11'], array_column(self::decode($this->request('GET', '/api/appointments'))['upcoming'], 'id'));
    }

    public function testDefectLifecycle(): void
    {
        self::assertSame(['fenster-schlafzimmer', 'wasserhahn-bad'], array_column(self::decode($this->request('GET', '/api/defects'))['defects'], 'id'), 'open first');

        $created = self::decode($this->request('POST', '/api/defects', ['titel' => 'Steckdose in der Küche locker', 'raum' => 'kueche', 'notiz' => 'Wackelt beim Einstecken']));
        self::assertSame('steckdose-in-der-kueche-locker', $created['id']);
        self::assertSame([['date' => '2026-10-24', 'status' => 'entdeckt', 'channel' => '', 'note' => 'Wackelt beim Einstecken']], $created['history']);
        self::assertSame('vermieter', $created['fields']['zustaendig']);
        self::assertArrayNotHasKey('kleinreparatur', $created['fields']);

        $reported = self::decode($this->request('POST', '/api/defects/steckdose-in-der-kueche-locker/status', ['status' => 'gemeldet', 'kanal' => 'E-Mail', 'nachfassen' => '2026-10-31']));
        self::assertSame(['gemeldet', '2026-10-24', '2026-10-31'], [$reported['evaluation']['status'], $reported['evaluation']['reported'], $reported['evaluation']['followUp']['date']]);
        $this->request('POST', '/api/defects/steckdose-in-der-kueche-locker/status', ['status' => 'gemeldet', 'kanal' => 'E-Mail']);
        self::assertCount(2, self::decode($this->request('GET', '/api/defects/steckdose-in-der-kueche-locker'))['history'], 'same status, same day: one row');

        $fixed = self::decode($this->request('POST', '/api/defects/steckdose-in-der-kueche-locker/status', ['status' => 'behoben', 'nachfassen' => null]));
        self::assertSame([false, '2026-10-24', null], [$fixed['evaluation']['open'], $fixed['evaluation']['fixedOn'], $fixed['evaluation']['followUp']]);
        self::assertArrayNotHasKey('nachfassen', $fixed['fields']);
        self::assertSame(['status' => 'unknown'], self::decode($this->request('POST', '/api/defects/steckdose-in-der-kueche-locker/status', ['status' => 'erledigt']))['fields']);
    }

    public function testMinorRepairsOfTheYear(): void
    {
        $year = self::decode($this->request('GET', '/api/defects/minor-repairs'));

        self::assertSame([2026, 38.5, 100.0, 300.0, 261.5], [$year['year'], $year['total'], $year['perCase'], $year['perYear'], $year['left']]);
        self::assertSame([], self::decode($this->request('GET', '/api/defects/minor-repairs?jahr=2025'))['cases']);
    }

    public function testDevices(): void
    {
        $devices = self::decode($this->request('GET', '/api/devices'))['devices'];
        self::assertSame(['gastherme', 'waschmaschine'], array_column($devices, 'id'));

        $washer = self::decode($this->request('GET', '/api/devices/waschmaschine'));
        self::assertSame(['2026-11-19', 'soon', 1], [$washer['evaluation']['liability']['until'], $washer['evaluation']['liability']['status'], $washer['photoCount']]);
        self::assertSame('Flusensieb einmal im Monat reinigen.', $washer['notes'], 'the image line is a photo, not text');

        $boiler = self::decode($this->request('GET', '/api/devices/gastherme'));
        self::assertSame(['heizungswartung'], array_column($boiler['maintenance'], 'id'));

        $created = self::decode($this->request('POST', '/api/devices', ['name' => 'Kühlschrank', 'raum' => 'kueche', 'kaufdatum' => '2026-10-01', 'garantie_monate' => '24', 'preis_eur' => '549,00']));
        self::assertSame(['kuehlschrank', 549, '2028-09-30'], [$created['id'], $created['fields']['preis_eur'], $created['evaluation']['warranty']['until']]);
    }

    public function testWasteFromTheMunicipalCalendar(): void
    {
        $waste = self::decode($this->request('GET', '/api/waste'));

        self::assertTrue($waste['fullService']);
        self::assertSame(['gelb' => '2026-10-27', 'bio' => '2026-10-30', 'rest' => '2026-11-03', 'papier' => '2026-11-06'], $waste['next']);
        self::assertNull($waste['error']);
        self::assertSame(1, $this->calendarRequests);

        $this->request('GET', '/api/waste');
        self::assertSame(1, $this->calendarRequests, 'within six hours from the cache');

        // The municipality is down: the last copy still answers, with the reason
        $this->calendarBody = null;
        $stale = self::decode($this->request('GET', '/api/waste?neu=1'));
        self::assertSame(['unreachable', '2026-10-27'], [$stale['error'], $stale['next']['gelb']]);
    }

    public function testWasteSettings(): void
    {
        self::assertSame(['kalender_url' => 'url'], self::decode($this->request('PUT', '/api/waste', ['kalender_url' => 'file:///etc/passwd']))['fields']);
        self::assertSame(['tonnen.0' => 'unknown'], self::decode($this->request('PUT', '/api/waste', ['tonnen' => [['name' => 'Glas', 'farbe' => 'lila']]]))['fields']);

        $saved = self::decode($this->request('PUT', '/api/waste', ['vollservice' => false, 'kalender_url' => 'https://neu.example.org/kalender.ics']));
        self::assertFalse($saved['fullService']);
        self::assertSame('https://neu.example.org/kalender.ics', $saved['url']);
        $note = $this->note(self::OBJECT . '/termine/muell.md');
        self::assertStringContainsString("vollservice: false\n", $note);
        self::assertStringContainsString("  - { id: gelb, name: Gelbe Tonne, farbe: bin-gelb, stichwort: Gelb }\n", $note, 'bins untouched');
    }

    public function testPaperlessIsOptional(): void
    {
        self::assertSame(['paperlessUrl' => null, 'readingDay' => 1], self::decode($this->request('GET', '/api/settings')));

        $saved = self::decode($this->request('PUT', '/api/settings', ['paperless_url' => 'https://paperless.example.org/']));
        self::assertSame('https://paperless.example.org', $saved['paperlessUrl']);
        self::assertStringContainsString("einstellungen:\n  ablesetag: 1\n  push: true\n  tonnen_selbst_rausstellen: false\n  paperless_url: https://paperless.example.org\n", $this->note('_haushalt.md'), 'one line added, the block stays');

        self::assertNull(self::decode($this->request('PUT', '/api/settings', ['paperless_url' => '']))['paperlessUrl']);
        self::assertStringNotContainsString('paperless', $this->note('_haushalt.md'));
        self::assertSame(['paperless_url' => 'url'], self::decode($this->request('PUT', '/api/settings', ['paperless_url' => 'kein link']))['fields']);
    }

    public function testObjectKnowsHeatingAndDuties(): void
    {
        $object = self::decode($this->request('GET', '/api/objects/active'))['object'];
        $bad = $this->request('PUT', '/api/objects/efh-musterstrasse', ['etag' => $object['etag'], 'changes' => ['heizung' => ['atom']], 'original' => []]);
        self::assertSame(['heizung' => 'unknown'], self::decode($bad)['fields']);

        $saved = $this->request('PUT', '/api/objects/efh-musterstrasse', ['etag' => $object['etag'], 'changes' => ['mieterpflichten' => ['garten', 'winterdienst', 'gehweg']], 'original' => ['mieterpflichten' => ['garten', 'winterdienst']]]);
        self::assertSame(['garten', 'winterdienst', 'gehweg'], self::decode($saved)['object']['mieterpflichten']);
    }
}
