<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session id, e.g. after login, to prevent session fixation. */
    public function regenerate(): void;

    public function destroy(): void;
}
