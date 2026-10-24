<?php

declare(strict_types=1);

namespace Wohnpilot\Calendar;

use PDO;

/**
 * Secret links to the calendar feed, one per person. Calendar apps cannot
 * sign in, so the link itself is the key. Only a hash of it is stored; a new
 * link replaces the old one. Not a cache: lives in the settings file, which
 * belongs in the backup.
 */
final class CalendarLinks
{
    /** 24 random bytes, base64url without padding */
    private const TOKEN = '/^[A-Za-z0-9_-]{32}$/';

    /** @param \Closure(): int|null $clock seconds since epoch, replaceable in tests */
    public function __construct(private readonly PDO $db, private readonly ?\Closure $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS calendar_links (
                person TEXT PRIMARY KEY,
                token_hash TEXT NOT NULL UNIQUE,
                created_at INTEGER NOT NULL
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

    /** A new link for $person; the one before stops working. Returns the token, which is not kept. */
    public function create(string $person): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->db->prepare(
            'INSERT INTO calendar_links (person, token_hash, created_at) VALUES (?, ?, ?)
             ON CONFLICT(person) DO UPDATE SET token_hash = excluded.token_hash, created_at = excluded.created_at',
        )->execute([$person, self::hash($token), $this->now()]);
        return $token;
    }

    /** When the current link of $person was made (seconds since epoch), or null if there is none. */
    public function createdAt(string $person): ?int
    {
        $statement = $this->db->prepare('SELECT created_at FROM calendar_links WHERE person = ?');
        $statement->execute([$person]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function revoke(string $person): void
    {
        $this->db->prepare('DELETE FROM calendar_links WHERE person = ?')->execute([$person]);
    }

    /** The person a link belongs to, or null for unknown and malformed tokens. */
    public function personFor(string $token): ?string
    {
        if (!preg_match(self::TOKEN, $token)) {
            return null;
        }
        $statement = $this->db->prepare('SELECT person FROM calendar_links WHERE token_hash = ?');
        $statement->execute([self::hash($token)]);
        $person = $statement->fetchColumn();
        return $person === false ? null : (string) $person;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
