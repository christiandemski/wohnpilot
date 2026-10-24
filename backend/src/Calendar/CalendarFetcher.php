<?php

declare(strict_types=1);

namespace Wohnpilot\Calendar;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;

/**
 * Loads a calendar from the internet, e.g. the municipal waste calendar.
 * Only public http(s) addresses: hosts that resolve to private or local
 * networks are refused, so the server cannot be used to look inside them.
 */
final class CalendarFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @param \Closure(string): list<string>|null $resolve host → IP addresses, replaceable in tests */
    public function __construct(
        private readonly ClientInterface $http = new Client([RequestOptions::TIMEOUT => 10, RequestOptions::CONNECT_TIMEOUT => 5]),
        private readonly ?\Closure $resolve = null,
    ) {
    }

    private function assertPublic(string $host): void
    {
        $addresses = $this->resolve === null ? (gethostbynamel($host) ?: []) : ($this->resolve)($host);
        if ($addresses === []) {
            throw new RuntimeException('unreachable');
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('private_address');
            }
        }
    }

    /** @throws RuntimeException with a short reason the app can show */
    public function fetch(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('url');
        }
        $this->assertPublic($host);
        try {
            $response = $this->http->request('GET', $url, [
                RequestOptions::HEADERS => ['Accept' => 'text/calendar, */*;q=0.5', 'User-Agent' => 'Wohnpilot'],
                // A redirect must lead to a public address too.
                RequestOptions::ALLOW_REDIRECTS => ['max' => 3, 'protocols' => ['http', 'https'], 'on_redirect' => fn ($request, $response, $uri) => $this->assertPublic($uri->getHost())],
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $e) {
            throw $e->getPrevious() instanceof RuntimeException ? $e->getPrevious() : new RuntimeException('unreachable');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('http_' . $response->getStatusCode());
        }
        $body = $response->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('too_large');
        }
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new RuntimeException('not_a_calendar');
        }
        return $body;
    }
}
