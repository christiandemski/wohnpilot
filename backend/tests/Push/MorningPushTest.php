<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Push;

use PDO;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Push\MorningPush;
use Wohnpilot\Push\PushSender;
use Wohnpilot\Push\PushSubscriptions;

final class MorningPushTest extends TestCase
{
    private PushSubscriptions $subscriptions;
    private FakePushSender $sender;

    protected function setUp(): void
    {
        $this->subscriptions = new PushSubscriptions(new PDO('sqlite::memory:'));
        $this->subscriptions->save('christian', 'https://push.example/phone', 'key', 'auth');
        $this->subscriptions->save('anna', 'https://push.example/laptop', 'key', 'auth');
        $this->sender = new FakePushSender();
    }

    private static function agenda(int $daysUntil): array
    {
        return [['ref' => ['type' => 'vertrag', 'id' => 'strom'], 'name' => 'Stadtwerke', 'kind' => 'kuendigung', 'date' => '2026-10-31', 'daysUntil' => $daysUntil, 'status' => 'soon']];
    }

    public function testSendsOncePerDayToEveryDevice(): void
    {
        $push = new MorningPush($this->subscriptions, $this->sender);

        $first = $push->run(self::agenda(7), '2026-10-24');
        $second = $push->run(self::agenda(7), '2026-10-24');

        self::assertSame([2, 0], [$first['sent'], $first['skipped']]);
        self::assertSame([0, 2], [$second['sent'], $second['skipped']], 'a second cron run the same day sends nothing');
        self::assertCount(2, $this->sender->sent);
        self::assertSame('2026-10-24', $this->subscriptions->find('https://push.example/phone')['last_sent']);
    }

    public function testQuietDayTouchesNoDevice(): void
    {
        $result = (new MorningPush($this->subscriptions, $this->sender))->run(self::agenda(9), '2026-10-24');

        self::assertNull($result['message']);
        self::assertSame([], $this->sender->sent);
    }

    public function testForgetsGoneDevicesAndRetriesFailedOnes(): void
    {
        $this->sender->outcomes = ['https://push.example/phone' => PushSender::GONE, 'https://push.example/laptop' => PushSender::FAILED];

        $result = (new MorningPush($this->subscriptions, $this->sender))->run(self::agenda(7), '2026-10-24');

        self::assertSame([0, 1, 1], [$result['sent'], $result['gone'], $result['failed']]);
        self::assertNull($this->subscriptions->find('https://push.example/phone'));
        self::assertNull($this->subscriptions->find('https://push.example/laptop')['last_sent'], 'a failed device gets the next run');
    }

    public function testDeviceBelongsToWhoeverSubscribedItLast(): void
    {
        $this->subscriptions->save('anna', 'https://push.example/phone', 'new-key', 'new-auth');
        $this->subscriptions->remove('https://push.example/phone', 'christian');

        self::assertSame(['anna', 'new-key'], [$this->subscriptions->find('https://push.example/phone')['person'], $this->subscriptions->find('https://push.example/phone')['p256dh']]);
        self::assertCount(2, $this->subscriptions->all());
    }
}
