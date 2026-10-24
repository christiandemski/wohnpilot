<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** The calendar link and the feed behind it. */
final class CalendarApiTest extends ApiTestCase
{
    public function testCreateLinkAndSubscribeWithoutSession(): void
    {
        $this->signIn();
        self::assertSame(['active' => false, 'createdAt' => null], self::decode($this->request('GET', '/api/calendar')));

        $created = self::decode($this->request('POST', '/api/calendar'));
        self::assertTrue($created['active']);
        self::assertMatchesRegularExpression('#/api/calendar/[A-Za-z0-9_-]{32}\.ics$#', $created['url']);
        self::assertTrue(self::decode($this->request('GET', '/api/calendar'))['active']);

        $this->session->destroy();
        $feed = $this->request('GET', parse_url($created['url'], PHP_URL_PATH), header: false);
        self::assertSame(200, $feed->getStatusCode());
        self::assertStringStartsWith('text/calendar', $feed->getHeaderLine('Content-Type'));
        $ics = str_replace("\r\n ", '', (string) $feed->getBody());
        self::assertStringContainsString('SUMMARY:Letzter Tag: Stadtwerke kündigen', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20261102', $ics);
        self::assertStringContainsString('SUMMARY:Zählerstand Strom an die Stadtwerke melden', $ics);
    }

    public function testNewOrRevokedLinkEndsTheOldOne(): void
    {
        $this->signIn();
        $first = parse_url(self::decode($this->request('POST', '/api/calendar'))['url'], PHP_URL_PATH);
        $second = parse_url(self::decode($this->request('POST', '/api/calendar'))['url'], PHP_URL_PATH);

        self::assertSame(404, $this->request('GET', $first)->getStatusCode());
        self::assertSame(200, $this->request('GET', $second)->getStatusCode());

        self::assertSame(204, $this->request('DELETE', '/api/calendar')->getStatusCode());
        self::assertSame(404, $this->request('GET', $second)->getStatusCode());
        self::assertFalse(self::decode($this->request('GET', '/api/calendar'))['active']);
    }

    public function testLinkSettingsNeedASession(): void
    {
        self::assertSame(401, $this->request('POST', '/api/calendar')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/calendar/' . str_repeat('a', 32) . '.ics')->getStatusCode());
    }
}
