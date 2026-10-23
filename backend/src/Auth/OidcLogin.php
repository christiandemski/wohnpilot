<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * OpenID Connect authorization code flow with PKCE against BearID.
 *
 * The ID token comes straight from the token endpoint over TLS, so its
 * issuer is checked through TLS instead of a signature (OIDC Core 3.1.3.7);
 * iss, aud, exp and nonce are still verified.
 */
final class OidcLogin implements LoginProvider
{
    private ?array $discovery = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $scope = 'openid profile email',
    ) {
    }

    public function start(Session $session): string
    {
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random(48);
        $session->set('oidc', ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier]);

        return $this->config('authorization_endpoint') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function finish(Session $session, array $query): Identity
    {
        $pending = $session->get('oidc');
        $session->remove('oidc');
        if (!is_array($pending) || !hash_equals($pending['state'], (string) ($query['state'] ?? ''))) {
            throw new LoginFailed('State mismatch');
        }
        if (isset($query['error'])) {
            throw new LoginFailed('BearID refused the login: ' . $query['error']);
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new LoginFailed('No authorization code');
        }

        $tokens = $this->post($this->config('token_endpoint'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $pending['verifier'],
        ]);
        $claims = $this->verifyIdToken((string) ($tokens['id_token'] ?? ''), $pending['nonce']);

        if (isset($tokens['access_token']) && $this->config('userinfo_endpoint', false) !== null) {
            $info = $this->get($this->config('userinfo_endpoint'), (string) $tokens['access_token']);
            if (($info['sub'] ?? null) === $claims['sub']) {
                $claims += $info;
            }
        }

        return new Identity(
            (string) $claims['sub'],
            (string) ($claims['preferred_username'] ?? $claims['nickname'] ?? $claims['sub']),
            (string) ($claims['name'] ?? $claims['given_name'] ?? ''),
            (string) ($claims['email'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    private function verifyIdToken(string $token, string $nonce): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new LoginFailed('No ID token');
        }
        try {
            $claims = json_decode(self::base64urlDecode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LoginFailed('Unreadable ID token');
        }
        $audience = (array) ($claims['aud'] ?? []);
        $checks = [
            'issuer' => rtrim((string) ($claims['iss'] ?? ''), '/') === rtrim($this->issuer, '/'),
            'audience' => in_array($this->clientId, $audience, true),
            'expiry' => (int) ($claims['exp'] ?? 0) > time() - 60,
            'nonce' => hash_equals($nonce, (string) ($claims['nonce'] ?? '')),
            'subject' => ($claims['sub'] ?? '') !== '',
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
        if ($failed !== []) {
            throw new LoginFailed('ID token check failed: ' . implode(', ', $failed));
        }
        return $claims;
    }

    private function config(string $key, bool $required = true): ?string
    {
        if ($this->discovery === null) {
            $url = rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
            try {
                $this->discovery = json_decode((string) $this->http->request('GET', $url)->getBody(), true, 32, JSON_THROW_ON_ERROR);
            } catch (GuzzleException | JsonException $e) {
                throw new LoginFailed('BearID discovery failed: ' . $e->getMessage());
            }
        }
        $value = $this->discovery[$key] ?? null;
        if ($value === null && $required) {
            throw new LoginFailed("BearID discovery has no $key");
        }
        return $value;
    }

    private function post(string $url, array $form): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'form_params' => $form,
                'auth' => [$this->clientId, $this->clientSecret],
                'headers' => ['Accept' => 'application/json'],
            ]);
            return json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (GuzzleException | JsonException $e) {
            throw new LoginFailed('Token request failed: ' . $e->getMessage());
        }
    }

    private function get(string $url, string $accessToken): array
    {
        try {
            $response = $this->http->request('GET', $url, ['headers' => ['Authorization' => "Bearer $accessToken", 'Accept' => 'application/json']]);
            return json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (GuzzleException | JsonException) {
            return [];
        }
    }

    private static function random(int $bytes = 24): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
