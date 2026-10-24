<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Wohnpilot\Push\PushSender;

/** Subscribing a device to the morning push and the test message. */
final class PushApiTest extends ApiTestCase
{
    private const DEVICE = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'secret'], 'contentEncoding' => 'aes128gcm'];

    public function testSubscribeTestAndUnsubscribe(): void
    {
        $this->signIn();
        self::assertSame(['publicKey' => 'BPublicTestKey'], self::decode($this->request('GET', '/api/push')));

        self::assertSame(204, $this->request('POST', '/api/push/subscriptions', self::DEVICE)->getStatusCode());
        self::assertSame(204, $this->request('POST', '/api/push/subscriptions', self::DEVICE)->getStatusCode(), 'sending it again is fine');

        self::assertSame(['result' => 'sent'], self::decode($this->request('POST', '/api/push/test', ['endpoint' => self::DEVICE['endpoint']])));
        self::assertSame('wohnpilot-test', $this->push->sent[0]['message']['tag']);

        self::assertSame(204, $this->request('POST', '/api/push/unsubscribe', ['endpoint' => self::DEVICE['endpoint']])->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/push/test', ['endpoint' => self::DEVICE['endpoint']])->getStatusCode());
    }

    public function testGoneDeviceIsForgotten(): void
    {
        $this->signIn();
        $this->request('POST', '/api/push/subscriptions', self::DEVICE);
        $this->push->outcomes[self::DEVICE['endpoint']] = PushSender::GONE;

        self::assertSame(['result' => 'gone'], self::decode($this->request('POST', '/api/push/test', ['endpoint' => self::DEVICE['endpoint']])));
        self::assertSame(404, $this->request('POST', '/api/push/test', ['endpoint' => self::DEVICE['endpoint']])->getStatusCode());
    }

    public function testRejectsBadSubscriptions(): void
    {
        $this->signIn();
        self::assertSame(422, $this->request('POST', '/api/push/subscriptions', ['endpoint' => 'http://insecure.example'] + self::DEVICE)->getStatusCode());
        self::assertSame(422, $this->request('POST', '/api/push/subscriptions', ['keys' => []] + self::DEVICE)->getStatusCode());

        $this->session->destroy();
        self::assertSame(401, $this->request('POST', '/api/push/subscriptions', self::DEVICE)->getStatusCode());
    }
}
