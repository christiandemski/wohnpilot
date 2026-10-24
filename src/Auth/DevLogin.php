<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

/**
 * Signs in as a fixed user without BearID. Only wired up with APP_ENV=dev.
 */
final class DevLogin implements LoginProvider
{
    public function __construct(private readonly string $username, private readonly string $callbackUrl)
    {
    }

    public function start(Session $session): string
    {
        $state = bin2hex(random_bytes(16));
        $session->set('login_state', $state);
        return $this->callbackUrl . '?state=' . $state;
    }

    public function finish(Session $session, array $query): Identity
    {
        $expected = $session->get('login_state');
        $session->remove('login_state');
        if (!is_string($expected) || !hash_equals($expected, (string) ($query['state'] ?? ''))) {
            throw new LoginFailed('State mismatch');
        }
        return new Identity('dev:' . $this->username, $this->username, ucfirst($this->username));
    }
}
