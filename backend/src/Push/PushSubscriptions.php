<?php

declare(strict_types=1);

namespace Wohnpilot\Push;

use PDO;

/**
 * Push subscriptions of the browsers and phones, one per device. Lives in the
 * settings file next to the calendar links (not a cache: belongs in the
 * backup). `last_sent` keeps the morning push to at most one a day.
 */
final class PushSubscriptions
{
    /** @param \Closure(): int|null $clock seconds since epoch, replaceable in tests */
    public function __construct(private readonly PDO $db, private readonly ?\Closure $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS push_subscriptions (
                endpoint TEXT PRIMARY KEY,
                person TEXT NOT NULL,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                encoding TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_sent TEXT
            )',
        );
    }

    public static function open(string $file): self
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        return new self(new PDO('sqlite:' . $file));
    }

    /** Saves the subscription of a device; sending it again changes nothing but the keys. */
    public function save(string $person, string $endpoint, string $p256dh, string $auth, string $encoding = 'aes128gcm'): void
    {
        $this->db->prepare(
            'INSERT INTO push_subscriptions (endpoint, person, p256dh, auth, encoding, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET person = excluded.person, p256dh = excluded.p256dh, auth = excluded.auth, encoding = excluded.encoding',
        )->execute([$endpoint, $person, $p256dh, $auth, $encoding, $this->now()]);
    }

    /** Removes a device; with $person only if it belongs to that person. */
    public function remove(string $endpoint, ?string $person = null): void
    {
        if ($person === null) {
            $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
        } else {
            $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND person = ?')->execute([$endpoint, $person]);
        }
    }

    /** @return array{endpoint: string, person: string, p256dh: string, auth: string, encoding: string, last_sent: ?string}|null */
    public function find(string $endpoint): ?array
    {
        $statement = $this->db->prepare('SELECT endpoint, person, p256dh, auth, encoding, last_sent FROM push_subscriptions WHERE endpoint = ?');
        $statement->execute([$endpoint]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array{endpoint: string, person: string, p256dh: string, auth: string, encoding: string, last_sent: ?string}> */
    public function all(): array
    {
        return $this->db->query('SELECT endpoint, person, p256dh, auth, encoding, last_sent FROM push_subscriptions ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markSent(string $endpoint, string $day): void
    {
        $this->db->prepare('UPDATE push_subscriptions SET last_sent = ? WHERE endpoint = ?')->execute([$day, $endpoint]);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
