<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Calendar;

use PDO;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Calendar\CalendarLinks;

final class CalendarLinksTest extends TestCase
{
    private CalendarLinks $links;

    protected function setUp(): void
    {
        $this->links = new CalendarLinks(new PDO('sqlite::memory:'), static fn (): int => 1_792_800_000);
    }

    public function testLinkLeadsToItsPerson(): void
    {
        $token = $this->links->create('christian');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        self::assertSame('christian', $this->links->personFor($token));
        self::assertSame(1_792_800_000, $this->links->createdAt('christian'));
        self::assertNull($this->links->createdAt('anna'));
    }

    public function testNewLinkReplacesTheOldOne(): void
    {
        $old = $this->links->create('christian');
        $new = $this->links->create('christian');

        self::assertNotSame($old, $new);
        self::assertNull($this->links->personFor($old));
        self::assertSame('christian', $this->links->personFor($new));
    }

    public function testRevokedAndForeignLinksLeadNowhere(): void
    {
        $token = $this->links->create('christian');
        $anna = $this->links->create('anna');
        $this->links->revoke('christian');

        self::assertNull($this->links->personFor($token));
        self::assertSame('anna', $this->links->personFor($anna));
        self::assertNull($this->links->personFor(str_repeat('a', 32)));
        self::assertNull($this->links->personFor("x' OR 1=1 --"));
    }

    public function testOnlyTheHashIsStored(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $token = (new CalendarLinks($pdo))->create('christian');

        self::assertNotContains($token, $pdo->query('SELECT token_hash FROM calendar_links')->fetchAll(PDO::FETCH_COLUMN));
    }
}
