<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Wohnpilot\App\Clock;
use Wohnpilot\Auth\AccountStore;
use Wohnpilot\Auth\ArraySession;
use Wohnpilot\Auth\BearId;
use Wohnpilot\Auth\Identity;
use Wohnpilot\Auth\Session;
use Wohnpilot\Config;
use Wohnpilot\Http\Api;
use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Services;
use Wohnpilot\Storage\FilesystemAdapter;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Tests\Fixtures;

final class ApiTest extends ApiTestCase
{
    private const STROM = 'objekte/efh-musterstrasse/zaehler/strom-haupt.md';

    public function testBearIdCookieSignsInWithoutAClick(): void
    {
        $this->addEmailToHousehold();

        $response = $this->request('GET', '/api/me', cookies: $this->bearIdCookie());

        self::assertSame(['user' => ['id' => 'christian', 'name' => 'Christian', 'role' => 'verwalter']], self::decode($response));
        self::assertSame('bearid:42', $this->session->get('identity')['subject']);
        self::assertSame(1, $this->session->regenerated);
    }

    public function testBearIdAccountWithoutReleaseIsTurnedAway(): void
    {
        $this->addEmailToHousehold();

        $response = $this->request('GET', '/api/me', cookies: $this->bearIdCookie(['apps' => ['garage']]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_released', self::decode($response)['error']);
    }

    public function testBearIdPersonMustBelongToTheHousehold(): void
    {
        $response = $this->request('GET', '/api/me', cookies: $this->bearIdCookie(['email' => 'fremd@example.org']));

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->session->get('identity'));
    }

    public function testBearIdButtonGoesToBearIdAndBackOnce(): void
    {
        $this->addEmailToHousehold();

        $first = $this->request('GET', '/api/auth/bearid');
        self::assertSame('https://id.codekorb.de/login?return=' . rawurlencode('http://localhost:8080/api/auth/bearid?zurueck=1'), $first->getHeaderLine('Location'));

        $noCookie = $this->request('GET', '/api/auth/bearid?zurueck=1');
        self::assertSame('/?login=fehlgeschlagen', $noCookie->getHeaderLine('Location'));

        $withCookie = $this->request('GET', '/api/auth/bearid?zurueck=1', cookies: $this->bearIdCookie());
        self::assertSame('/', $withCookie->getHeaderLine('Location'));
        self::assertSame('bearid:42', $this->session->get('identity')['subject']);
    }

    public function testLogoutAlsoLeavesBearId(): void
    {
        $this->signIn();

        $withCookie = self::decode($this->request('POST', '/api/auth/logout', cookies: $this->bearIdCookie()));
        $without = self::decode($this->request('POST', '/api/auth/logout'));

        self::assertSame('https://id.codekorb.de/logout?return=' . rawurlencode('http://localhost:8080/'), $withCookie['redirect']);
        self::assertNull($without['redirect']);
        self::assertNull($this->session->get('identity'));
    }

    public function testRequiresLogin(): void
    {
        $response = $this->request('GET', '/api/overview');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', self::decode($response)['error']);
    }

    public function testDevLoginFlow(): void
    {
        $start = $this->request('GET', '/api/auth/login');
        self::assertSame(302, $start->getStatusCode());
        $location = $start->getHeaderLine('Location');
        self::assertStringStartsWith('http://localhost:8080/api/auth/callback?state=', $location);

        $callback = $this->request('GET', '/api/auth/callback?' . parse_url($location, PHP_URL_QUERY));

        self::assertSame('/', $callback->getHeaderLine('Location'));
        self::assertSame(1, $this->session->regenerated);
        self::assertSame(['user' => ['id' => 'christian', 'name' => 'Christian', 'role' => 'verwalter']], self::decode($this->request('GET', '/api/me')));
    }

    public function testPasswordLogin(): void
    {
        $response = $this->request('POST', '/api/auth/password', ['email' => 'Christian@example.org', 'password' => 'richtig-langes-passwort']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['user' => ['id' => 'christian', 'name' => 'Christian', 'role' => 'verwalter']], self::decode($response));
        self::assertSame(1, $this->session->regenerated);
        self::assertSame(200, $this->request('GET', '/api/overview')->getStatusCode());
    }

    public function testWrongPasswordIsRejectedWithoutSession(): void
    {
        $response = $this->request('POST', '/api/auth/password', ['email' => 'christian@example.org', 'password' => 'falsch']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_login', self::decode($response)['error']);
        self::assertNull($this->session->get('identity'));
    }

    public function testTooManyAttemptsAreSlowedDown(): void
    {
        for ($i = 0; $i < AccountStore::MAX_FAILURES_PER_EMAIL; $i++) {
            $this->request('POST', '/api/auth/password', ['email' => 'christian@example.org', 'password' => 'falsch']);
        }

        $response = $this->request('POST', '/api/auth/password', ['email' => 'christian@example.org', 'password' => 'richtig-langes-passwort']);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('rate_limited', self::decode($response)['error']);
    }

    public function testAccountWithoutHouseholdMembershipGetsNoSession(): void
    {
        $response = $this->request('POST', '/api/auth/password', ['email' => 'fremd@example.org', 'password' => 'richtig-langes-passwort']);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->session->get('identity'));
    }

    public function testPasswordLoginNeedsTheHeader(): void
    {
        $response = $this->request('POST', '/api/auth/password', ['email' => 'christian@example.org', 'password' => 'richtig-langes-passwort'], header: false);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testLoginMethods(): void
    {
        self::assertSame(['bearid' => true, 'password' => true, 'redirect' => 'dev'], self::decode($this->request('GET', '/api/auth/methods')));
    }

    public function testForgedCallbackIsRejected(): void
    {
        $this->request('GET', '/api/auth/login');

        $callback = $this->request('GET', '/api/auth/callback?state=falsch');

        self::assertSame('/?login=fehlgeschlagen', $callback->getHeaderLine('Location'));
        self::assertNull($this->session->get('identity'));
    }

    public function testStrangerIsNotLetIn(): void
    {
        $this->signIn('fremd');

        self::assertSame(403, $this->request('GET', '/api/overview')->getStatusCode());
    }

    public function testOverview(): void
    {
        $this->signIn();

        $data = self::decode($this->request('GET', '/api/overview'));

        self::assertSame('2026-10-24', $data['today']);
        self::assertSame('efh-musterstrasse', $data['object']['id']);
        self::assertSame(['strom-haupt', 'wasser-haupt'], array_column($data['meters'], 'id'));
        self::assertSame(
            [
                'kind' => 'meldung',
                'deadlineId' => 'strom-ablesung-2026',
                'title' => 'Zählerstand Strom an die Stadtwerke melden',
                'from' => '2026-12-05',
                'dueDate' => '2026-12-20',
                'daysUntil' => 57,
                'status' => 'ok',
                'meterIds' => ['strom-haupt'],
            ],
            $data['next'],
            'a requested reading wins over the voluntary rhythm',
        );
        self::assertSame('strom-ablesung-2026', $data['meters'][0]['request']['id']);
        self::assertNull($data['meters'][1]['request']);
        self::assertSame(['dueDate' => '2026-11-01', 'daysUntil' => 8, 'status' => 'ok'], $data['meters'][0]['schedule']);
        self::assertEqualsWithDelta(246.0, $data['meters'][0]['currentMonth']['amount'], 1e-9);
        self::assertSame(23702.0, $data['meters'][0]['latest']['value']);
        self::assertSame('2026-11-01', $data['meters'][1]['schedule']['dueDate'], 'water read on the reading day 01.10. counts for October');
    }

    public function testUrgentRequestNamesOnlyMetersStillToRead(): void
    {
        @mkdir("$this->root/objekte/efh-musterstrasse/fristen", 0775, true);
        file_put_contents(
            "$this->root/objekte/efh-musterstrasse/fristen/ablesung-vermieter.md",
            "---\ntyp: frist\nid: ablesung-vermieter\nobjekt: efh-musterstrasse\ntitel: Zählerstände an Vermieter\nab: 2026-10-20\ndatum: 2026-10-30\nbezieht_sich_auf: [strom-haupt, wasser-haupt]\n---\n",
        );
        $this->signIn();

        $data = self::decode($this->request('GET', '/api/overview'));

        self::assertSame('ablesung-vermieter', $data['next']['deadlineId']);
        self::assertSame('soon', $data['next']['status']);
        self::assertSame(['wasser-haupt'], $data['next']['meterIds'], 'electricity was read on 2026-10-24');
        self::assertSame('strom-ablesung-2026', $data['meters'][0]['request']['id']);
        self::assertSame('ablesung-vermieter', $data['meters'][1]['request']['id']);
    }

    public function testDoneRequestFallsBackToVoluntaryRhythm(): void
    {
        $path = "$this->root/objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md";
        file_put_contents($path, str_replace("erstellt_von: christian\n", "erstellt_von: christian\nerledigt: 2026-10-24\n", file_get_contents($path)));
        $this->signIn();

        $data = self::decode($this->request('GET', '/api/overview'));

        self::assertSame(['kind' => 'ablesung', 'dueDate' => '2026-11-01', 'daysUntil' => 8, 'status' => 'ok', 'meterIds' => ['strom-haupt', 'wasser-haupt']], $data['next']);
        self::assertNull($data['meters'][0]['request']);
    }

    public function testMeterDetail(): void
    {
        $this->signIn();

        $data = self::decode($this->request('GET', '/api/meters/strom-haupt'));

        self::assertCount(4, $data['readings']);
        self::assertSame('2026-10-24', $data['readings'][0]['date']);
        self::assertCount(12, $data['months']);
        self::assertSame(90, $data['estimate']['suggestedInstalment'], '3085 kWh × 29.8 ct + 150 € ≈ 1069 € a year');
        self::assertSame('Zähler hängt im Keller links neben der Tür.', $data['notes']);
    }

    public function testAddReadingWritesOneRowAndIsIdempotent(): void
    {
        $this->signIn();
        $before = file_get_contents("$this->root/" . self::STROM);
        $this->clock('2026-11-01');

        $response = $this->request('POST', '/api/meters/strom-haupt/readings', ['date' => '2026-11-01', 'value' => 23770.0]);
        $this->request('POST', '/api/meters/strom-haupt/readings', ['date' => '2026-11-01', 'value' => 23770]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2026-11-01', self::decode($response)['latest']['date']);
        $after = file_get_contents("$this->root/" . self::STROM);
        self::assertSame(1, substr_count($after, '| 2026-11-01 | 23770.0 | regulär |'));
        self::assertCount(count(explode("\n", $before)) + 2, explode("\n", $after), 'one row plus geaendert_von');
    }

    public function testReadingKeepsAnEditMadeInTheNotesApp(): void
    {
        $this->signIn();
        $this->request('GET', '/api/meters/strom-haupt');
        file_put_contents("$this->root/" . self::STROM, file_get_contents("$this->root/" . self::STROM) . "Neu in der Notes-App.\n");

        $this->request('POST', '/api/meters/strom-haupt/readings', ['date' => '2026-10-24', 'value' => 23710.5, 'occasion' => 'Stichtag']);

        $after = file_get_contents("$this->root/" . self::STROM);
        self::assertStringEndsWith("Neu in der Notes-App.\n", $after);
        self::assertStringContainsString('| 2026-10-24 | 23710.5 | Stichtag |', $after);
    }

    public function testWritesNeedTheHeader(): void
    {
        $this->signIn();

        $response = $this->request('POST', '/api/meters/strom-haupt/readings', ['value' => 1], header: false);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvalidReading(): void
    {
        $this->signIn();

        $response = $this->request('POST', '/api/meters/strom-haupt/readings', ['date' => '2026-12-01', 'value' => 'viel']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['date' => 'future', 'value' => 'number'], self::decode($response)['fields']);
    }

    public function testUpdateMergesChangesMadeElsewhere(): void
    {
        $this->signIn();
        $etag = self::decode($this->request('GET', '/api/meters/strom-haupt'))['etag'];
        $file = "$this->root/" . self::STROM;
        file_put_contents($file, str_replace('zaehlernummer: 1ESY1160123456', 'zaehlernummer: 1ESY1160999999', file_get_contents($file)));

        $response = $this->request('PUT', '/api/meters/strom-haupt', [
            'etag' => $etag,
            'changes' => ['ablese_intervall' => 'quartalsweise'],
            'original' => ['ablese_intervall' => 'monatlich'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $data = self::decode($response);
        self::assertSame('quartalsweise', $data['interval']);
        self::assertSame('1ESY1160999999', $data['number']);
    }

    public function testUpdateReportsRealConflicts(): void
    {
        $this->signIn();
        $etag = self::decode($this->request('GET', '/api/meters/strom-haupt'))['etag'];
        $file = "$this->root/" . self::STROM;
        file_put_contents($file, str_replace('raum: keller', 'raum: flur', file_get_contents($file)));

        $response = $this->request('PUT', '/api/meters/strom-haupt', [
            'etag' => $etag,
            'changes' => ['raum' => 'kueche'],
            'original' => ['raum' => 'keller'],
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['raum' => ['mine' => 'kueche', 'theirs' => 'flur']], self::decode($response)['fields']);
        self::assertStringContainsString('raum: flur', file_get_contents($file));
    }

    public function testFirstSetupCreatesHouseholdObjectAndMeter(): void
    {
        foreach (array_keys(Fixtures::allNotes()) as $path) {
            unlink("$this->root/$path");
        }
        $this->app = $this->buildApp();
        $this->signIn();

        $object = $this->request('POST', '/api/objects', [
            'art' => 'wohnung',
            'bezeichnung' => 'Wohnung Hauptstraße',
            'adresse' => 'Hauptstraße 1, 12345 Ort',
            'einzug' => '2027-02-01',
            'raeume' => ['Keller', 'Küche', 'Bad'],
        ]);
        self::assertSame(201, $object->getStatusCode(), (string) $object->getBody());
        self::assertSame(['keller', 'kueche', 'bad'], self::decode($object)['object']['raeume']);

        $meter = $this->request('POST', '/api/meters', ['medium' => 'strom', 'raum' => 'keller', 'zaehlernummer' => '1ESY 123', 'nachkommastellen' => 1]);
        self::assertSame(201, $meter->getStatusCode(), (string) $meter->getBody());
        self::assertSame('strom-keller', self::decode($meter)['id']);

        self::assertSame(
            "---\ntyp: zaehler\nid: strom-keller\nobjekt: wohnung-hauptstrasse\nmedium: strom\nzaehlernummer: 1ESY 123\neinheit: kWh\n"
            . "stellen: 6\nnachkommastellen: 1\nraum: keller\nablese_intervall: monatlich\nerstellt_von: christian\n---\n"
            . "| Datum      | Stand | Anlass  | Foto | Von |\n|------------|-------|---------|------|-----|\n",
            file_get_contents("$this->root/objekte/wohnung-hauptstrasse/zaehler/strom-keller.md"),
        );
        $household = file_get_contents("$this->root/_haushalt.md");
        self::assertStringContainsString("aktives_objekt: wohnung-hauptstrasse\n", $household);
        self::assertStringContainsString("personen: [{ id: christian, name: Christian, rolle: verwalter }]\n", $household);
    }

    public function testFirstSetupOnlyForBootstrapUsers(): void
    {
        foreach (array_keys(Fixtures::allNotes()) as $path) {
            unlink("$this->root/$path");
        }
        $this->app = $this->buildApp();
        $this->signIn('fremd');

        self::assertSame(403, $this->request('POST', '/api/objects', ['art' => 'haus'])->getStatusCode());
    }

    private function addEmailToHousehold(): void
    {
        $file = "$this->root/_haushalt.md";
        file_put_contents($file, str_replace("    rolle: verwalter
", "    rolle: verwalter
    email: christian@example.org
", file_get_contents($file)));
    }
}
