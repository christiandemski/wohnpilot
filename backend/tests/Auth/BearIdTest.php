<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Auth\BearId;
use Wohnpilot\Auth\LoginFailed;

final class BearIdTest extends TestCase
{
    private const ISSUER = 'https://id.codekorb.de';

    private string $secret;
    private BearId $bearId;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->bearId = new BearId(self::ISSUER, base64_encode(sodium_crypto_sign_publickey($pair)), 'wohnpilot', fn (): int => $this->now);
    }

    /** Signs like BearID's Jwt::sign. */
    public static function token(string $secret, array $claims, string $alg = 'EdDSA'): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $h = $b64(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => 'test']));
        $p = $b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return "$h.$p." . $b64(sodium_crypto_sign_detached("$h.$p", $secret));
    }

    private function claims(array $overrides = []): array
    {
        return $overrides + [
            'iss' => self::ISSUER,
            'sub' => '1',
            'name' => 'Christian',
            'email' => 'Christian.Demski@Example.org',
            'admin' => false,
            'apps' => ['garage', 'wohnpilot'],
            'ver' => 0,
            'iat' => $this->now - 60,
            'exp' => $this->now + 3600,
        ];
    }

    public function testValidCookieGivesTheIdentity(): void
    {
        $identity = $this->bearId->identity(['bearid' => self::token($this->secret, $this->claims())]);

        self::assertSame('bearid:1', $identity->subject);
        self::assertSame('Christian', $identity->name);
        self::assertSame('christian.demski@example.org', $identity->email);
        self::assertSame('', $identity->username);
    }

    public function testAdminsGetInWithoutRelease(): void
    {
        $token = self::token($this->secret, $this->claims(['admin' => true, 'apps' => []]));

        self::assertNotNull($this->bearId->identity(['bearid' => $token]));
    }

    public function testToolMustBeReleased(): void
    {
        $token = self::token($this->secret, $this->claims(['apps' => ['garage']]));

        $this->expectException(LoginFailed::class);
        $this->expectExceptionMessage('not_allowed');
        $this->bearId->identity(['bearid' => $token]);
    }

    public function testInvalidTokensAreIgnored(): void
    {
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $valid = self::token($this->secret, $this->claims());
        [$h, $p, $s] = explode('.', $valid);
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode($this->claims(['admin' => true]))), '+/', '-_'), '=');

        $cases = [
            'no cookie' => [],
            'garbage' => ['bearid' => 'abc'],
            'other key' => ['bearid' => self::token($foreign, $this->claims())],
            'other issuer' => ['bearid' => self::token($this->secret, $this->claims(['iss' => 'https://evil.example']))],
            'expired' => ['bearid' => self::token($this->secret, $this->claims(['exp' => $this->now - 1]))],
            'alg none' => ['bearid' => self::token($this->secret, $this->claims(), 'none')],
            'changed payload' => ['bearid' => "$h.$tamperedPayload.$s"],
        ];
        foreach ($cases as $name => $cookies) {
            self::assertNull($this->bearId->identity($cookies), $name);
        }
    }

    public function testLoginAndLogoutUrls(): void
    {
        self::assertSame(
            'https://id.codekorb.de/login?return=https%3A%2F%2Fwohnpilot.codekorb.de%2Fapi%2Fauth%2Fbearid%3Fzurueck%3D1',
            $this->bearId->loginUrl('https://wohnpilot.codekorb.de/api/auth/bearid?zurueck=1'),
        );
        self::assertSame('https://id.codekorb.de/logout?return=https%3A%2F%2Fwohnpilot.codekorb.de%2F', $this->bearId->logoutUrl('https://wohnpilot.codekorb.de/'));
    }
}
