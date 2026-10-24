<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Calendar;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wohnpilot\Calendar\CalendarFetcher;

final class CalendarFetcherTest extends TestCase
{
    private static function fetcher(array $responses, array $ips = ['93.184.216.34']): CalendarFetcher
    {
        return new CalendarFetcher(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]), static fn (string $host): array => $ips);
    }

    public function testLoadsACalendar(): void
    {
        self::assertSame("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", self::fetcher([new Response(200, [], "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n")])->fetch('https://abfuhr.example.org/k.ics'));
    }

    /** @return array<string, array{list<string>, string}> */
    public static function internalAddresses(): array
    {
        return [
            'loopback' => [['127.0.0.1'], 'private_address'],
            'home network' => [['192.168.178.1'], 'private_address'],
            'link local' => [['169.254.169.254'], 'private_address'],
            'one of several' => [['93.184.216.34', '10.0.0.5'], 'private_address'],
            'unknown host' => [[], 'unreachable'],
        ];
    }

    #[DataProvider('internalAddresses')]
    public function testRefusesInternalAddresses(array $ips, string $reason): void
    {
        $this->expectExceptionObject(new RuntimeException($reason));
        self::fetcher([], $ips)->fetch('https://kalender.example.org/k.ics');
    }

    public function testRedirectToAnInternalAddressIsRefused(): void
    {
        $fetcher = new CalendarFetcher(
            new Client(['handler' => HandlerStack::create(new MockHandler([new Response(302, ['Location' => 'http://router.intern/k.ics']), new Response(200, [], 'BEGIN:VCALENDAR')]))]),
            static fn (string $host): array => $host === 'router.intern' ? ['192.168.178.1'] : ['93.184.216.34'],
        );

        $this->expectExceptionObject(new RuntimeException('private_address'));
        $fetcher->fetch('https://kalender.example.org/k.ics');
    }

    public function testNamesWhatWentWrong(): void
    {
        $reason = static function (array $responses, string $url = 'https://a.example.org/k.ics'): string {
            try {
                self::fetcher($responses)->fetch($url);
                return 'ok';
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        };

        self::assertSame('http_404', $reason([new Response(404)]));
        self::assertSame('not_a_calendar', $reason([new Response(200, [], '<html>Wartung</html>')]));
        self::assertSame('url', $reason([], 'ftp://a.example.org/k.ics'));
        self::assertSame('too_large', $reason([new Response(200, [], 'BEGIN:VCALENDAR' . str_repeat('x', CalendarFetcher::MAX_BYTES))]));
    }
}
