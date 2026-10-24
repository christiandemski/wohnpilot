<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Psr\Http\Message\ResponseInterface;

/** Pairing a kiosk tablet and what it may do (today 2026-10-24). */
final class KioskApiTest extends ApiTestCase
{
    /** Signed in: a code. Then signed out, as the tablet is. */
    private function code(): string
    {
        $this->signIn();
        $code = self::decode($this->request('POST', '/api/kiosk/pairing'))['code'];
        $this->session->destroy();
        return $code;
    }

    private function pair(string $code): ResponseInterface
    {
        return $this->request('POST', '/api/kiosk/pair', ['code' => $code, 'name' => 'Tablet Flur']);
    }

    private static function token(ResponseInterface $response): string
    {
        self::assertMatchesRegularExpression('/^wohnpilot_kiosk=[A-Za-z0-9_-]{43}; Path=\/api\/kiosk; Max-Age=31536000; HttpOnly; SameSite=Strict/', $response->getHeaderLine('Set-Cookie'));
        return (string) preg_replace('/^wohnpilot_kiosk=([^;]+);.*$/', '$1', $response->getHeaderLine('Set-Cookie'));
    }

    public function testPairWithACodeOnceAndSeeTheBoard(): void
    {
        self::assertSame(401, $this->request('GET', '/api/kiosk/board')->getStatusCode(), 'not paired');
        $code = $this->code();
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        self::assertSame(['code' => 'invalid_code'], self::decode($this->pair('000000' === $code ? '111111' : '000000'))['fields']);
        $paired = $this->pair($code);
        self::assertSame(201, $paired->getStatusCode());
        $token = self::token($paired);
        self::assertSame(422, $this->pair($code)->getStatusCode(), 'a code works once');

        $board = $this->request('GET', '/api/kiosk/board', cookies: ['wohnpilot_kiosk' => $token]);
        self::assertSame(200, $board->getStatusCode());
        $data = self::decode($board);
        self::assertSame(['id' => 'christian', 'name' => 'Christian', 'role' => 'verwalter'], $data['user']);
        self::assertSame('2026-10-24', $data['today']);
        self::assertSame(['strom-haupt', 'wasser-haupt'], array_column($data['meters'], 'id'));
        self::assertNotContains('muell', array_column($data['items'], 'kind'));
        self::assertTrue(array_reduce($data['items'], static fn (bool $ok, array $i): bool => $ok && $i['daysUntil'] <= 7, true), 'only the next seven days');
        self::assertStringStartsWith('wohnpilot_kiosk=' . $token . ';', $board->getHeaderLine('Set-Cookie'), 'renewed while in use');
    }

    public function testTheTabletOnlyGetsTheKiosk(): void
    {
        $token = self::token($this->pair($this->code()));
        self::assertSame(401, $this->request('GET', '/api/overview', cookies: ['wohnpilot_kiosk' => $token])->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/contracts', cookies: ['wohnpilot_kiosk' => $token])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/kiosk/maintenance/heckenschnitt/done', header: false, cookies: ['wohnpilot_kiosk' => $token])->getStatusCode(), 'writes need the header');
    }

    public function testTicksOffMaintenanceForThePersonWhoPaired(): void
    {
        $token = self::token($this->pair($this->code()));
        $done = $this->request('POST', '/api/kiosk/maintenance/heizungswartung/done', [], cookies: ['wohnpilot_kiosk' => $token]);
        self::assertSame(200, $done->getStatusCode());
        self::assertStringContainsString('| 2026-10-24 | christian', file_get_contents("$this->root/objekte/efh-musterstrasse/wartung/heizungswartung.md"));

        $this->request('DELETE', '/api/kiosk/maintenance/heizungswartung/done/2026-10-24', cookies: ['wohnpilot_kiosk' => $token]);
        self::assertStringNotContainsString('| 2026-10-24 |', file_get_contents("$this->root/objekte/efh-musterstrasse/wartung/heizungswartung.md"));
    }

    public function testRevokedOrUnpairedTabletIsOut(): void
    {
        $paired = $this->pair($this->code());
        $token = self::token($paired);

        $this->signIn();
        $devices = self::decode($this->request('GET', '/api/kiosk/devices'))['devices'];
        self::assertSame(['Tablet Flur'], array_column($devices, 'name'));
        self::assertSame([], self::decode($this->request('DELETE', '/api/kiosk/devices/' . $devices[0]['id']))['devices']);
        $this->session->destroy();
        self::assertSame(401, $this->request('GET', '/api/kiosk/board', cookies: ['wohnpilot_kiosk' => $token])->getStatusCode());

        $second = self::token($this->pair($this->code()));
        self::assertSame(204, $this->request('POST', '/api/kiosk/unpair', cookies: ['wohnpilot_kiosk' => $second])->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/kiosk/board', cookies: ['wohnpilot_kiosk' => $second])->getStatusCode());
    }

    public function testWrongCodesAreSlowedDown(): void
    {
        $code = $this->code();
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(422, $this->pair($wrong)->getStatusCode());
        }
        self::assertSame(429, $this->pair($code)->getStatusCode(), 'even the right code waits now');
    }

    public function testSignedInPersonMayLookAtTheBoard(): void
    {
        $this->signIn();
        self::assertSame(200, $this->request('GET', '/api/kiosk/board')->getStatusCode());
    }
}
