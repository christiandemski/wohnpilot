<?php

declare(strict_types=1);

namespace Wohnpilot\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Web Push with VAPID (minishlink/web-push), over the app's Guzzle client. */
final class WebPushSender implements PushSender
{
    private WebPush $webPush;

    public function __construct(string $publicKey, string $privateKey, string $subject)
    {
        $factory = new HttpFactory();
        $this->webPush = new WebPush(
            ['VAPID' => ['subject' => $subject, 'publicKey' => $publicKey, 'privateKey' => $privateKey]],
            // A day is enough: the next morning brings a new summary.
            ['TTL' => 86400, 'urgency' => 'normal', 'topic' => 'digest'],
            new Client(['timeout' => 15, 'connect_timeout' => 5]),
            $factory,
            $factory,
        );
    }

    public function send(array $subscription, array $message): string
    {
        $report = $this->webPush->sendOneNotification(
            new Subscription($subscription['endpoint'], $subscription['p256dh'], $subscription['auth'], $subscription['encoding']),
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        if ($report->isSuccess()) {
            return self::SENT;
        }
        if ($report->isSubscriptionExpired()) {
            return self::GONE;
        }
        error_log('Wohnpilot push failed: ' . $report->getReason());
        return self::FAILED;
    }
}
