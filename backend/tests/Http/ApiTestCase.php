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

/**
 * Runs the API against a copy of the fixtures in a temp folder, with a fixed
 * day (2026-10-24 unless clock() sets another) and an in-memory session.
 */
abstract class ApiTestCase extends TestCase
{
    protected string $root;
    protected ArraySession $session;
    protected App $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wohnpilot-api-' . bin2hex(random_bytes(4));
        foreach (array_keys(Fixtures::allNotes()) as $path) {
            @mkdir(dirname("$this->root/$path"), 0775, true);
            copy(Fixtures::root() . "/$path", "$this->root/$path");
        }
        $this->session = new ArraySession();
        $this->bearIdPair = sodium_crypto_sign_keypair();
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

    protected ?string $today = '2026-10-24';
    protected string $bearIdPair;

    protected function bearIdCookie(array $claims = []): array
    {
        $claims += ['iss' => 'https://id.codekorb.de', 'sub' => '42', 'name' => 'Christian', 'email' => 'christian@example.org', 'admin' => false, 'apps' => ['wohnpilot'], 'exp' => time() + 3600];
        return ['bearid' => \Wohnpilot\Tests\Auth\BearIdTest::token(sodium_crypto_sign_secretkey($this->bearIdPair), $claims)];
    }

    protected function clock(string $today): void
    {
        $this->today = $today;
        $this->app = $this->buildApp();
    }

    protected function buildApp(): App
    {
        $config = new Config(['APP_ENV' => 'dev', 'DEV_LOGIN_USER' => 'christian', 'WOHNPILOT_USERS' => 'christian', 'SYNC_MAX_AGE' => '0']);
        $root = $this->root;
        $session = $this->session;
        $today = $this->today;
        $bearIdKey = base64_encode(sodium_crypto_sign_publickey($this->bearIdPair));
        $services = new class ($config, $root, $session, $today, $bearIdKey) extends Services {
            protected ?NoteIndex $testIndex = null;
            protected ?StorageAdapter $testStorage = null;
            protected ?AccountStore $testAccounts = null;

            public function accounts(): AccountStore
            {
                if ($this->testAccounts === null) {
                    $this->testAccounts = new AccountStore(new PDO('sqlite::memory:'));
                    $this->testAccounts->set('christian@example.org', 'christian', 'richtig-langes-passwort', 'Christian');
                    $this->testAccounts->set('fremd@example.org', 'fremd', 'richtig-langes-passwort');
                }
                return $this->testAccounts;
            }

            public function __construct(Config $config, private readonly string $root, private readonly ArraySession $testSession, private readonly string $today, private readonly string $bearIdKey)
            {
                parent::__construct($config, sys_get_temp_dir());
            }

            public function bearId(): BearId
            {
                return new BearId('https://id.codekorb.de', $this->bearIdKey, 'wohnpilot');
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

    protected function signIn(string $username = 'christian'): void
    {
        $this->session->set('identity', (new Identity("dev:$username", $username, ucfirst($username)))->toArray());
    }

    protected function request(string $method, string $uri, ?array $body = null, bool $header = true, array $cookies = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query)->withCookieParams($cookies);
        if ($header) {
            $request = $request->withHeader('X-Requested-With', 'wohnpilot');
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body));
        }
        return $this->app->handle($request);
    }

    protected static function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }
}
