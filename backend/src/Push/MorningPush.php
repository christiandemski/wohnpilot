<?php

declare(strict_types=1);

namespace Wohnpilot\Push;

/**
 * The cron job's work (bin/notify.php): the digest of the day to every
 * device that has not had one today. Safe to run twice; devices that are
 * gone are forgotten.
 */
final class MorningPush
{
    public function __construct(
        private readonly PushSubscriptions $subscriptions,
        private readonly PushSender $sender,
        private readonly DailyDigest $digest = new DailyDigest(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $agenda items of AgendaService::forOverview
     * @return array{message: ?array, sent: int, gone: int, failed: int, skipped: int}
     */
    public function run(array $agenda, string $today): array
    {
        $result = ['message' => $this->digest->build($agenda, $today), 'sent' => 0, 'gone' => 0, 'failed' => 0, 'skipped' => 0];
        if ($result['message'] === null) {
            return $result;
        }
        foreach ($this->subscriptions->all() as $subscription) {
            if ($subscription['last_sent'] === $today) {
                $result['skipped']++;
                continue;
            }
            $outcome = $this->sender->send($subscription, $result['message']);
            if ($outcome === PushSender::SENT) {
                $this->subscriptions->markSent($subscription['endpoint'], $today);
                $result['sent']++;
            } elseif ($outcome === PushSender::GONE) {
                $this->subscriptions->remove($subscription['endpoint']);
                $result['gone']++;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }
}
