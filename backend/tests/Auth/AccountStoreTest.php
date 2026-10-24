<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Auth;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Auth\AccountStore;
use Wohnpilot\Auth\LoginFailed;

final class AccountStoreTest extends TestCase
{
    private const PASSWORD = 'richtig-langes-passwort';

    private int $now = 1_800_000_000;
    private AccountStore $accounts;

    protected function setUp(): void
    {
        $this->accounts = new AccountStore(new PDO('sqlite::memory:'), fn (): int => $this->now);
        $this->accounts->set('Christian@Example.org ', 'christian', self::PASSWORD, 'Christian');
    }

    public function testVerifiesAndReturnsThePerson(): void
    {
        $identity = $this->accounts->verify('christian@example.org', self::PASSWORD, '1.2.3.4');

        self::assertSame('christian', $identity->username);
        self::assertSame('Christian', $identity->name);
        self::assertSame('christian@example.org', $identity->email);
        self::assertSame('local:christian@example.org', $identity->subject);
    }

    public function testPasswordIsStoredAsHashOnly(): void
    {
        $db = new PDO('sqlite::memory:');
        (new AccountStore($db))->set('a@example.org', 'anna', self::PASSWORD);

        $hash = $db->query('SELECT password_hash FROM accounts')->fetchColumn();

        self::assertStringNotContainsString(self::PASSWORD, $hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function testWrongPasswordAndUnknownEmailFailTheSameWay(): void
    {
        foreach ([['christian@example.org', 'falsch'], ['niemand@example.org', self::PASSWORD]] as [$email, $password]) {
            try {
                $this->accounts->verify($email, $password, '1.2.3.4');
                self::fail('Expected LoginFailed');
            } catch (LoginFailed $e) {
                self::assertSame('invalid', $e->getMessage());
            }
        }
    }

    public function testFiveFailuresLockTheEmailForFifteenMinutes(): void
    {
        for ($i = 0; $i < AccountStore::MAX_FAILURES_PER_EMAIL; $i++) {
            $this->failingAttempt('christian@example.org', '10.0.0.' . $i);
        }

        // Even the right password is refused while locked …
        $this->assertRateLimited('christian@example.org', self::PASSWORD, '10.0.1.1');

        // … and works again after the window.
        $this->now += AccountStore::WINDOW_SECONDS + 1;
        self::assertSame('christian', $this->accounts->verify('christian@example.org', self::PASSWORD, '10.0.1.1')->username);
    }

    public function testManyFailuresFromOneIpLockThatIp(): void
    {
        for ($i = 0; $i < AccountStore::MAX_FAILURES_PER_IP; $i++) {
            $this->failingAttempt("person$i@example.org", '6.6.6.6');
        }

        $this->assertRateLimited('christian@example.org', self::PASSWORD, '6.6.6.6');
        self::assertSame('christian', $this->accounts->verify('christian@example.org', self::PASSWORD, '7.7.7.7')->username);
    }

    public function testNewPasswordReplacesTheOldOne(): void
    {
        $this->accounts->set('christian@example.org', 'christian', 'ein-ganz-neues-passwort');

        $this->failingAttempt('christian@example.org', '1.2.3.4', self::PASSWORD);
        self::assertSame('christian', $this->accounts->verify('christian@example.org', 'ein-ganz-neues-passwort', '1.2.3.4')->username);
    }

    public function testRejectsWeakInput(): void
    {
        foreach ([['keine-mail', 'christian', self::PASSWORD], ['a@example.org', 'Christian D', self::PASSWORD], ['a@example.org', 'anna', 'kurz']] as [$email, $person, $password]) {
            try {
                $this->accounts->set($email, $person, $password);
                self::fail("Expected rejection of $email / $person");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testListAndRemove(): void
    {
        self::assertSame(['christian@example.org'], array_column($this->accounts->all(), 'email'));
        self::assertTrue($this->accounts->remove('CHRISTIAN@example.org'));
        self::assertTrue($this->accounts->isEmpty());
    }

    private function failingAttempt(string $email, string $ip, string $password = 'falsch'): void
    {
        try {
            $this->accounts->verify($email, $password, $ip);
            self::fail('Expected LoginFailed');
        } catch (LoginFailed $e) {
            self::assertSame('invalid', $e->getMessage());
        }
    }

    private function assertRateLimited(string $email, string $password, string $ip): void
    {
        try {
            $this->accounts->verify($email, $password, $ip);
            self::fail('Expected rate limit');
        } catch (LoginFailed $e) {
            self::assertSame('rate_limited', $e->getMessage());
        }
    }
}
