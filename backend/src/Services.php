<?php

declare(strict_types=1);

namespace Wohnpilot;

use GuzzleHttp\Client;
use RuntimeException;
use Wohnpilot\App\Clock;
use Wohnpilot\App\HouseholdService;
use Wohnpilot\App\MeterService;
use Wohnpilot\App\NoteRepository;
use Wohnpilot\Auth\DevLogin;
use Wohnpilot\Auth\LoginProvider;
use Wohnpilot\Auth\NativeSession;
use Wohnpilot\Auth\OidcLogin;
use Wohnpilot\Auth\Session;
use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Storage\FilesystemAdapter;
use Wohnpilot\Storage\NextcloudNotesAdapter;
use Wohnpilot\Storage\StorageAdapter;

/** Builds the app's objects from the config, each once and only when needed. */
class Services
{
    private array $instances = [];

    public function __construct(public readonly Config $config, private readonly string $backendDir)
    {
    }

    public function storage(): StorageAdapter
    {
        return $this->instances[__FUNCTION__] ??= match ($this->config->get('STORAGE', 'nextcloud')) {
            'nextcloud' => NextcloudNotesAdapter::connect(
                $this->config->require('NEXTCLOUD_URL'),
                $this->config->require('NEXTCLOUD_USER'),
                $this->config->require('NEXTCLOUD_APP_PASSWORD'),
                $this->config->get('NEXTCLOUD_FOLDER', 'Wohnpilot'),
            ),
            'filesystem' => new FilesystemAdapter($this->path($this->config->get('FILESYSTEM_ROOT', 'var/notes'))),
            default => throw new RuntimeException('STORAGE must be nextcloud or filesystem'),
        };
    }

    public function index(): NoteIndex
    {
        return $this->instances[__FUNCTION__] ??= NoteIndex::open($this->path($this->config->get('INDEX_FILE', 'var/index.sqlite')));
    }

    public function notes(): NoteRepository
    {
        return $this->instances[__FUNCTION__] ??= new NoteRepository($this->storage(), $this->index(), (int) $this->config->get('SYNC_MAX_AGE', '30'));
    }

    public function clock(): Clock
    {
        return $this->instances[__FUNCTION__] ??= new Clock();
    }

    public function household(): HouseholdService
    {
        return $this->instances[__FUNCTION__] ??= new HouseholdService($this->notes(), $this->config->list('WOHNPILOT_USERS'));
    }

    public function meters(): MeterService
    {
        return $this->instances[__FUNCTION__] ??= new MeterService($this->notes(), $this->household(), $this->clock());
    }

    public function session(): Session
    {
        return $this->instances[__FUNCTION__] ??= new NativeSession(str_starts_with($this->appUrl(), 'https://'));
    }

    public function login(): LoginProvider
    {
        $callback = $this->appUrl() . '/api/auth/callback';
        if ($this->config->isDev() && $this->config->get('DEV_LOGIN_USER') !== '') {
            return $this->instances[__FUNCTION__] ??= new DevLogin($this->config->get('DEV_LOGIN_USER'), $callback);
        }
        return $this->instances[__FUNCTION__] ??= new OidcLogin(
            new Client(['timeout' => 10, 'connect_timeout' => 5]),
            $this->config->require('BEARID_ISSUER'),
            $this->config->require('BEARID_CLIENT_ID'),
            $this->config->require('BEARID_CLIENT_SECRET'),
            $callback,
        );
    }

    public function appUrl(): string
    {
        return rtrim($this->config->get('APP_URL', 'http://localhost:8080'), '/');
    }

    private function path(string $path): string
    {
        return preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) ? $path : $this->backendDir . '/' . $path;
    }
}
