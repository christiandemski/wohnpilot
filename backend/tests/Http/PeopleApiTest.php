<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Wohnpilot\App\Roles;

/** People of the household, roles and who takes care of what (today 2026-10-24). */
final class PeopleApiTest extends ApiTestCase
{
    private function household(): string
    {
        return file_get_contents("$this->root/_haushalt.md");
    }

    public function testEveryoneSeesThePeople(): void
    {
        $this->signIn('alex');
        $people = self::decode($this->request('GET', '/api/people'))['people'];

        self::assertSame(['christian', 'alex'], array_column($people, 'id'));
        self::assertSame(['verwalter', 'mitglied'], array_column($people, 'rolle'));
        self::assertSame([false, true], array_column($people, 'self'));
        // christian has an e-mail emergency account (see ApiTestCase)
        self::assertSame([true, false], array_column($people, 'emergency'));
        self::assertSame(['id' => 'alex', 'name' => 'Alex', 'role' => 'mitglied'], self::decode($this->request('GET', '/api/me'))['user']);
    }

    public function testAdministratorAddsChangesAndRemovesAPerson(): void
    {
        $this->signIn();
        $created = $this->request('POST', '/api/people', ['name' => 'Oma Inge', 'rolle' => 'gast', 'email' => 'Inge@Example.org']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertStringContainsString('{ id: oma-inge, name: Oma Inge, rolle: gast, email: inge@example.org }', $this->household());

        // Signs in with BearID by that e-mail address, as a guest
        $this->session->set('identity', (new \Wohnpilot\Auth\Identity('bearid:77', 'inge', 'Inge', 'inge@example.org'))->toArray());
        self::assertSame(['id' => 'oma-inge', 'name' => 'Oma Inge', 'role' => 'gast'], self::decode($this->request('GET', '/api/me'))['user']);
        $this->signIn();

        $changed = $this->request('PUT', '/api/people/oma-inge', ['rolle' => 'mitglied', 'email' => '', 'bearid' => '77']);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertStringContainsString('{ id: oma-inge, name: Oma Inge, rolle: mitglied, bearid: "77" }', $this->household());
        self::assertStringContainsString("geaendert_von: christian\n", $this->household());

        self::assertSame(200, $this->request('DELETE', '/api/people/oma-inge')->getStatusCode());
        self::assertStringNotContainsString('oma-inge', $this->household());
        // Everything else of the note stays
        self::assertStringContainsString("einstellungen:\n  ablesetag: 1\n", $this->household());
        self::assertStringContainsString("Haushalt mit allen Personen und Einstellungen.\n", $this->household());
    }

    public function testChecksOfThePeopleList(): void
    {
        $this->signIn();
        self::assertSame(['name' => 'required', 'email' => 'email'], self::decode($this->request('POST', '/api/people', ['name' => '', 'email' => 'kein-at']))['fields']);
        self::assertSame(['rolle' => 'unknown'], self::decode($this->request('POST', '/api/people', ['name' => 'Bob', 'rolle' => 'chef']))['fields']);
        self::assertSame(201, $this->request('POST', '/api/people', ['name' => 'Bob', 'email' => 'bob@example.org'])->getStatusCode());
        self::assertSame(['email' => 'taken'], self::decode($this->request('PUT', '/api/people/alex', ['email' => 'BOB@example.org']))['fields']);

        self::assertSame('self', self::decode($this->request('DELETE', '/api/people/christian'))['error']);
        self::assertSame('last_admin', self::decode($this->request('PUT', '/api/people/christian', ['rolle' => 'mitglied']))['error']);
        self::assertSame(404, $this->request('PUT', '/api/people/niemand', ['name' => 'X'])->getStatusCode());
    }

    public function testAnIdStaysWithWhoLeft(): void
    {
        $this->signIn();
        self::assertSame(200, $this->request('DELETE', '/api/people/alex')->getStatusCode());
        // rauchmelder still names alex as responsible: a new Alex gets another id
        $people = self::decode($this->request('POST', '/api/people', ['name' => 'Alex']))['people'];
        self::assertSame('alex-2', $people[1]['id']);
    }

    public function testMembersEnterButDoNotManage(): void
    {
        $this->signIn('alex');
        self::assertSame(200, $this->request('POST', '/api/maintenance/rauchmelder/done', ['datum' => '2026-10-24'])->getStatusCode());
        self::assertSame(201, $this->request('POST', '/api/defects', ['titel' => 'Tür klemmt', 'raum' => 'flur'])->getStatusCode());
        self::assertSame(201, $this->request('POST', '/api/calendar')->getStatusCode());

        foreach ([
            ['POST', '/api/people', ['name' => 'X']],
            ['PUT', '/api/people/alex', ['rolle' => 'verwalter']],
            ['PUT', '/api/settings', ['ablesetag' => 5]],
            ['PUT', '/api/waste', ['url' => '']],
            ['PUT', '/api/objects/efh-musterstrasse', ['changes' => ['wohnflaeche_m2' => 1], 'original' => [], 'etag' => '']],
            ['DELETE', '/api/entries/termin/schornsteinfeger-2026-11?etag=x', null],
        ] as [$method, $uri, $body]) {
            $response = $this->request($method, $uri, $body);
            self::assertSame(403, $response->getStatusCode(), "$method $uri");
            self::assertSame(['error' => 'role', 'message' => 'Not allowed for the role mitglied', 'role' => 'mitglied'], self::decode($response));
        }
        self::assertFileExists("$this->root/objekte/efh-musterstrasse/termine/schornsteinfeger-2026-11.md");
    }

    public function testGuestsOnlyRead(): void
    {
        $this->signIn();
        $this->request('PUT', '/api/people/alex', ['rolle' => Roles::GUEST]);
        $this->signIn('alex');

        self::assertSame(200, $this->request('GET', '/api/overview')->getStatusCode());
        self::assertSame(201, $this->request('POST', '/api/calendar')->getStatusCode());
        self::assertSame(204, $this->request('POST', '/api/sync')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/maintenance/rauchmelder/done', ['datum' => '2026-10-24'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/meters/strom-haupt/readings', ['date' => '2026-10-24', 'value' => 23720.5])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/kiosk/pairing')->getStatusCode());
        self::assertStringNotContainsString('2026-10-24', file_get_contents("$this->root/objekte/efh-musterstrasse/wartung/rauchmelder.md"));
    }

    public function testAGuestsTabletOnlyShowsTheBoard(): void
    {
        $this->signIn();
        $code = self::decode($this->request('POST', '/api/kiosk/pairing'))['code'];
        $paired = $this->request('POST', '/api/kiosk/pair', ['code' => $code, 'name' => 'Flur']);
        preg_match('/wohnpilot_kiosk=([^;]+)/', $paired->getHeaderLine('Set-Cookie'), $m);
        $cookie = ['wohnpilot_kiosk' => $m[1]];
        $this->session->set('identity', null);
        // The person who paired it is a guest now (changed in the Notes app)
        file_put_contents("$this->root/_haushalt.md", preg_replace('/rolle: verwalter/', 'rolle: gast', $this->household(), 1));

        self::assertSame(200, $this->request('GET', '/api/kiosk/board', cookies: $cookie)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/kiosk/maintenance/rauchmelder/done', [], cookies: $cookie)->getStatusCode());
    }

    public function testResponsiblePersonOnTasksAppointmentsAndDeadlines(): void
    {
        $this->signIn();
        $task = $this->request('POST', '/api/maintenance', ['titel' => 'Dachrinne reinigen', 'intervall_monate' => 12, 'person' => 'alex']);
        self::assertSame(201, $task->getStatusCode(), (string) $task->getBody());
        self::assertStringContainsString("person: alex\n", file_get_contents("$this->root/objekte/efh-musterstrasse/wartung/dachrinne-reinigen.md"));

        self::assertSame(['person' => 'unknown'], self::decode($this->request('POST', '/api/appointments', ['titel' => 'Ablesung', 'datum' => '2026-11-02', 'person' => 'niemand']))['fields']);
        $appointment = self::decode($this->request('POST', '/api/appointments', ['titel' => 'Ablesung', 'datum' => '2026-11-02', 'person' => 'christian']));
        self::assertSame('christian', $appointment['fields']['person']);

        $deadline = self::decode($this->request('GET', '/api/deadlines/strom-ablesung-2026'));
        $updated = $this->request('PUT', '/api/deadlines/strom-ablesung-2026', ['changes' => ['person' => 'alex'], 'original' => ['person' => null], 'etag' => $deadline['etag']]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());

        $agenda = self::decode($this->request('GET', '/api/overview'))['agenda'];
        $who = [];
        foreach ($agenda as $item) {
            $who[$item['ref']['type'] . ':' . $item['ref']['id']] = $item['person'] ?? null;
        }
        self::assertSame(['id' => 'alex', 'name' => 'Alex'], $who['wartung:rauchmelder']);
        self::assertSame(['id' => 'alex', 'name' => 'Alex'], $who['frist:strom-ablesung-2026']);
        self::assertSame(['id' => 'christian', 'name' => 'Christian'], $who['termin:' . $appointment['id']]);
        self::assertNull($who['wartung:heckenschnitt']);

        // Unassigning again
        $task = self::decode($this->request('GET', '/api/maintenance/dachrinne-reinigen'));
        $this->request('PUT', '/api/maintenance/dachrinne-reinigen', ['changes' => ['person' => ''], 'original' => ['person' => 'alex'], 'etag' => $task['etag']]);
        self::assertStringNotContainsString('person:', file_get_contents("$this->root/objekte/efh-musterstrasse/wartung/dachrinne-reinigen.md"));
    }
}
