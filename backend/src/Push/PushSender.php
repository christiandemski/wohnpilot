<?php

declare(strict_types=1);

namespace Wohnpilot\Push;

/** Delivers one push message to one device. */
interface PushSender
{
    public const SENT = 'sent';
    /** The device is gone (unsubscribed, app removed): forget it. */
    public const GONE = 'gone';
    public const FAILED = 'failed';

    /**
     * @param array{endpoint: string, p256dh: string, auth: string, encoding: string} $subscription
     * @param array{title: string, body: string, url: string, tag: string} $message
     * @return string SENT, GONE or FAILED
     */
    public function send(array $subscription, array $message): string;
}
