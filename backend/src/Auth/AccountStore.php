<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

use InvalidArgumentException;
use PDO;

/**
 * Accounts for signing in with e-mail and password, next to BearID.
 *
 * Unlike the note index this is no cache: it lives in its own SQLite file
 * and belongs in the backup. Passwords are only stored as hashes, never in
 * notes. Failed attempts are counted per e-mail and per IP address.
 */
final class AccountStore
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const WINDOW_SECONDS = 15 * 60;

    /** Verified against when the e-mail is unknown, so both cases take the same time. */
    private ?string $dummyHash = null;

    /** @param \Closure(): int $clock seconds since epoch, replaceable in tests */
    public function __construct(private readonly PDO $db, private readonly ?\Closure $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS accounts (
                email TEXT PRIMARY KEY,
                person TEXT NOT NULL,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                changed_at INTEGER NOT NULL
            )',
        );
        $this->db->exec('CREATE TABLE IF NOT EXISTS failures (key TEXT NOT NULL, at INTEGER NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS failures_key ON failures (key, at)');
    }

    public static function open(string $file): self
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        return new self(new PDO('sqlite:' . $file));
    }

    /** Creates an account or replaces its password. $person is the id in _haushalt.md. */
    public function set(string $email, string $person, string $password, string $name = ''): void
    {
        $email = self::normalize($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Not a valid e-mail address');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $person)) {
            throw new InvalidArgumentException('Person id must be a slug like "christian"');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password needs at least ' . self::MIN_PASSWORD_LENGTH . ' characters');
        }
        $now = $this->now();
        $this->db->prepare(
            'INSERT INTO accounts (email, person, name, password_hash, created_at, changed_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(email) DO UPDATE SET person = excluded.person, name = excluded.name,
               password_hash = excluded.password_hash, changed_at = excluded.changed_at',
        )->execute([$email, $person, $name !== '' ? $name : ucfirst($person), password_hash($password, PASSWORD_DEFAULT), $now, $now]);
        $this->clearFailures("email:$email");
    }

    public function remove(string $email): bool
    {
        $statement = $this->db->prepare('DELETE FROM accounts WHERE email = ?');
        $statement->execute([self::normalize($email)]);
        return $statement->rowCount() > 0;
    }

    /** @return list<array{email: string, person: string, name: string, changed_at: int}> */
    public function all(): array
    {
        return $this->db->query('SELECT email, person, name, changed_at FROM accounts ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isEmpty(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 0;
    }

    /**
     * Checks e-mail and password.
     *
     * @throws LoginFailed with message "rate_limited" or "invalid"
     */
    public function verify(string $email, string $password, string $ip): Identity
    {
        $email = self::normalize($email);
        $emailKey = "email:$email";
        $ipKey = "ip:$ip";
        if ($this->failures($emailKey) >= self::MAX_FAILURES_PER_EMAIL || $this->failures($ipKey) >= self::MAX_FAILURES_PER_IP) {
            throw new LoginFailed('rate_limited');
        }

        $statement = $this->db->prepare('SELECT * FROM accounts WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        $hash = $account['password_hash'] ?? ($this->dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));

        if (!password_verify($password, $hash) || $account === null) {
            $this->recordFailure($emailKey);
            $this->recordFailure($ipKey);
            throw new LoginFailed('invalid');
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE accounts SET password_hash = ? WHERE email = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
        }
        $this->clearFailures($emailKey);

        return new Identity('local:' . $email, $account['person'], $account['name'], $email);
    }

    private function failures(string $key): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM failures WHERE key = ? AND at > ?');
        $statement->execute([$key, $this->now() - self::WINDOW_SECONDS]);
        return (int) $statement->fetchColumn();
    }

    private function recordFailure(string $key): void
    {
        $this->db->prepare('INSERT INTO failures (key, at) VALUES (?, ?)')->execute([$key, $this->now()]);
        $this->db->prepare('DELETE FROM failures WHERE at <= ?')->execute([$this->now() - self::WINDOW_SECONDS]);
    }

    private function clearFailures(string $key): void
    {
        $this->db->prepare('DELETE FROM failures WHERE key = ?')->execute([$key]);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
