<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Wohnpilot\App\Clock;
use Wohnpilot\Auth\ArraySession;
use Wohnpilot\Auth\Identity;
use Wohnpilot\Auth\Session;
use Wohnpilot\Config;
use Wohnpilot\Http\Api;
use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Services;
use Wohnpilot\Storage\FilesystemAdapter;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Tests\Fixtures;

final class ApiTest extends TestCase
{
    private const STROM = 'objekte/efh-musterstrasse/zaehler/strom-haupt.md';

    private string $root;
    private ArraySession $session;
    private App $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wohnpilot-api-' . bin2hex(random_bytes(4));
        foreach (array_keys(Fixtures::allNotes()) as $path) {
            @mkdir(dirname("$this->root/$path"), 0775, true);
            copy(Fixtures::root() . "/$path", "$this->root/$path");
        }
        $this->session = new ArraySession();
        $this->app = $this->buildApp();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
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
        self::assertSame(['user' => ['id' => 'christian', 'name' => 'Christian']], self::decode($this->request('GET', '/api/me')));
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
        self::assertSame(['kind' => 'ablesung', 'dueDate' => '2026-11-01', 'daysUntil' => 8, 'status' => 'ok', 'meterCount' => 1], $data['next']);
        self::assertEqualsWithDelta(246.0, $data['meters'][0]['currentMonth']['amount'], 1e-9);
        self::assertSame(23702.0, $data['meters'][0]['latest']['value']);
        self::assertSame('2026-11-01', $data['meters'][1]['schedule']['dueDate'], 'water read on the reading day 01.10. counts for October');
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
            "---\ntyp: zaehler\nid: strom-keller\nobjekt: wohnung-hauptstrasse\nmedium: strom\nzaehlernummer: '1ESY 123'\neinheit: kWh\n"
            . "stellen: 6\nnachkommastellen: 1\nraum: keller\nablese_intervall: monatlich\nerstellt_von: christian\n---\n"
            . "| Datum      | Stand | Anlass  | Foto | Von |\n|------------|-------|---------|------|-----|\n",
            file_get_contents("$this->root/objekte/wohnung-hauptstrasse/zaehler/strom-keller.md"),
        );
        $household = file_get_contents("$this->root/_haushalt.md");
        self::assertStringContainsString("aktives_objekt: wohnung-hauptstrasse\n", $household);
        self::assertStringContainsString("personen: [{ id: christian, name: Christian, rolle: verwalter, bearid: 'dev:christian' }]\n", $household);
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

    private ?string $today = '2026-10-24';

    private function clock(string $today): void
    {
        $this->today = $today;
        $this->app = $this->buildApp();
    }

    private function buildApp(): App
    {
        $config = new Config(['APP_ENV' => 'dev', 'DEV_LOGIN_USER' => 'christian', 'WOHNPILOT_USERS' => 'christian', 'SYNC_MAX_AGE' => '0']);
        $root = $this->root;
        $session = $this->session;
        $today = $this->today;
        $services = new class ($config, $root, $session, $today) extends Services {
            private ?NoteIndex $testIndex = null;
            private ?StorageAdapter $testStorage = null;

            public function __construct(Config $config, private readonly string $root, private readonly ArraySession $testSession, private readonly string $today)
            {
                parent::__construct($config, sys_get_temp_dir());
            }

            public function storage(): StorageAdapter
            {
                return $this->testStorage ??= new FilesystemAdapter($this->root);
            }

            public function index(): NoteIndex
            {
                return $this->testIndex ??= new NoteIndex(new PDO('sqlite::memory:'));
            }

            public function session(): Session
            {
                return $this->testSession;
            }

            public function clock(): Clock
            {
                return new Clock($this->today);
            }
        };
        return Api::create($services);
    }

    private function signIn(string $username = 'christian'): void
    {
        $this->session->set('identity', (new Identity("dev:$username", $username, ucfirst($username)))->toArray());
    }

    private function request(string $method, string $uri, ?array $body = null, bool $header = true): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($header) {
            $request = $request->withHeader('X-Requested-With', 'wohnpilot');
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body));
        }
        return $this->app->handle($request);
    }

    private static function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }
}
