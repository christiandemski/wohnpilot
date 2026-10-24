<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

/**
 * Sign-in through BearID (id.codekorb.de), "Weg B" from the BearID README:
 * BearID sets the cookie "bearid" for all subdomains, holding a JWT signed
 * with Ed25519. Wohnpilot verifies it like bearid-guard/guard.php does,
 * with the public key from bearid-guard/public.key.
 *
 * Only people who are admin in BearID or have the tool (BEARID_APP) in
 * their "apps" claim get in.
 */
final class BearId
{
    public const COOKIE = 'bearid';

    /**
     * @param string $publicKey base64 Ed25519 public key (content of public.key)
     * @param \Closure(): int|null $clock seconds since epoch, replaceable in tests
     */
    public function __construct(
        private readonly string $issuer,
        private readonly string $publicKey,
        private readonly string $app,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * The person behind the cookie, or null if there is no valid token.
     *
     * @param array<string, mixed> $cookies
     * @throws LoginFailed "not_allowed" if the token is valid but the tool is not released
     */
    public function identity(array $cookies): ?Identity
    {
        $token = $cookies[self::COOKIE] ?? '';
        $claims = is_string($token) ? $this->verify($token) : null;
        if ($claims === null) {
            return null;
        }
        $apps = is_array($claims['apps'] ?? null) ? $claims['apps'] : [];
        if (empty($claims['admin']) && !in_array($this->app, $apps, true)) {
            throw new LoginFailed('not_allowed');
        }
        return new Identity(
            'bearid:' . $claims['sub'],
            '',
            (string) ($claims['name'] ?? ''),
            mb_strtolower(trim((string) ($claims['email'] ?? ''))),
        );
    }

    /** Claims of a valid token, or null (wrong signature, issuer, algorithm or expired). */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64d($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }
        $signature = self::b64d($s);
        $key = base64_decode(trim($this->publicKey), true);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached($signature, "$h.$p", $key)) {
            return null;
        }
        $claims = json_decode(self::b64d($p), true);
        if (!is_array($claims) || ($claims['sub'] ?? '') === '') {
            return null;
        }
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($this->issuer, '/') || (int) ($claims['exp'] ?? 0) < $this->now()) {
            return null;
        }
        return $claims;
    }

    /** BearID login; an expired token is renewed there without asking. */
    public function loginUrl(string $return): string
    {
        return rtrim($this->issuer, '/') . '/login?return=' . rawurlencode($return);
    }

    public function logoutUrl(string $return): string
    {
        return rtrim($this->issuer, '/') . '/logout?return=' . rawurlencode($return);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private static function b64d(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: '';
    }
}
