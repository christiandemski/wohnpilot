<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

/** PHP session with a hardened cookie; started lazily. */
final class NativeSession implements Session
{
    public function __construct(private readonly bool $secure, private readonly int $lifetimeSeconds = 60 * 60 * 24 * 30)
    {
    }

    public function get(string $key): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax']);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.gc_maxlifetime', (string) $this->lifetimeSeconds);
        ini_set('session.use_strict_mode', '1');
        session_name('wohnpilot');
        session_set_cookie_params([
            'lifetime' => $this->lifetimeSeconds,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
