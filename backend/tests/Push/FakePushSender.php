<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Push;

use Wohnpilot\Push\PushSender;

/** Records messages instead of sending them; endpoints can be marked gone or failing. */
final class FakePushSender implements PushSender
{
    /** @var list<array{endpoint: string, message: array}> */
    public array $sent = [];
    /** @var array<string, string> endpoint => GONE or FAILED */
    public array $outcomes = [];

    public function send(array $subscription, array $message): string
    {
        $outcome = $this->outcomes[$subscription['endpoint']] ?? self::SENT;
        if ($outcome === self::SENT) {
            $this->sent[] = ['endpoint' => $subscription['endpoint'], 'message' => $message];
        }
        return $outcome;
    }
}
