<?php

declare(strict_types=1);

namespace Wohnpilot;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Settings from the environment (.env). Secrets such as the Nextcloud app
 * password only live here, never in notes or in the browser.
 */
final readonly class Config
{
    /** @param array<string, string> $env */
    public function __construct(private array $env)
    {
    }

    /**
     * Loads .env from the backend folder (deployment) or the repository root
     * (development); real environment variables win.
     */
    public static function load(string $backendDir): self
    {
        foreach ([$backendDir, dirname($backendDir)] as $dir) {
            if (is_file("$dir/.env")) {
                Dotenv::createImmutable($dir)->safeLoad();
                break;
            }
        }
        $env = [];
        // Real environment variables win over .env (Dotenv cannot see getenv-only variables).
        foreach (getenv() + $_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        return new self($env);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->env[$key] ?? '';
        return $value === '' ? $default : $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === '') {
            throw new RuntimeException("Setting $key is missing in .env");
        }
        return $value;
    }

    public function isDev(): bool
    {
        return $this->get('APP_ENV', 'prod') === 'dev';
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key)))));
    }
}
