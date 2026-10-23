<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

/**
 * How users sign in. OIDC against BearID first; another flow can replace it
 * without touching the rest of the app.
 */
interface LoginProvider
{
    /** Prepares the login and returns the URL to send the browser to. */
    public function start(Session $session): string;

    /**
     * Completes the login from the callback query parameters.
     *
     * @param array<string, string> $query
     * @throws LoginFailed
     */
    public function finish(Session $session, array $query): Identity;
}
