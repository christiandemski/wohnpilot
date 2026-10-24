<?php

declare(strict_types=1);

namespace Wohnpilot\Auth;

use PDO;

/**
 * Kiosk devices (a tablet on the wall): paired once with a short code made
 * by a signed-in person, then known by a long secret in a cookie. A device
 * acts for that person, but only on the kiosk routes. Codes are valid for
 * 10 minutes and once; codes and secrets are stored as hashes only. Wrong
 * codes are slowed down per address. Lives in the settings file (backup).
 */
final class KioskDevices
{
    public const CODE_SECONDS = 600;
    public const MAX_ATTEMPTS = 10;
    public const ATTEMPT_WINDOW = 900;
    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/';

    /** @param \Closure(): int|null $clock seconds since epoch, replaceable in tests */
    public function __construct(private readonly PDO $db, private readonly ?\Closure $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE IF NOT EXISTS kiosk_codes (code_hash TEXT PRIMARY KEY, person TEXT NOT NULL UNIQUE, expires_at INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS kiosk_devices (id TEXT PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, person TEXT NOT NULL, name TEXT NOT NULL, created_at INTEGER NOT NULL, last_seen INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS kiosk_attempts (address_hash TEXT NOT NULL, at INTEGER NOT NULL)');
    }

    public static function open(string $file): self
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        return new self(new PDO('sqlite:' . $file));
    }

    /** A new six-digit code for $person; an earlier one of theirs stops working. */
    public function createCode(string $person): array
    {
        $now = $this->now();
        $this->db->prepare('DELETE FROM kiosk_codes WHERE expires_at < ? OR person = ?')->execute([$now, $person]);
        do {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $statement = $this->db->prepare('INSERT OR IGNORE INTO kiosk_codes (code_hash, person, expires_at) VALUES (?, ?, ?)');
            $statement->execute([self::hash($code), $person, $now + self::CODE_SECONDS]);
        } while ($statement->rowCount() === 0);
        return ['code' => $code, 'expiresAt' => $now + self::CODE_SECONDS];
    }

    /**
     * Pairs a device with a code. Returns its secret (kept only in the
     * device's cookie) and the person, or null for a wrong or old code.
     *
     * @return array{token: string, id: string, person: string}|null
     * @throws LoginFailed 'rate_limited' after too many wrong codes from $address
     */
    public function pair(string $code, string $name, string $address): ?array
    {
        $now = $this->now();
        $addressHash = self::hash($address);
        $this->db->prepare('DELETE FROM kiosk_attempts WHERE at < ?')->execute([$now - self::ATTEMPT_WINDOW]);
        $count = $this->db->prepare('SELECT COUNT(*) FROM kiosk_attempts WHERE address_hash = ?');
        $count->execute([$addressHash]);
        if ((int) $count->fetchColumn() >= self::MAX_ATTEMPTS) {
            throw new LoginFailed('rate_limited');
        }

        $find = $this->db->prepare('SELECT person FROM kiosk_codes WHERE code_hash = ? AND expires_at >= ?');
        $find->execute([self::hash(trim($code)), $now]);
        $person = $find->fetchColumn();
        if ($person === false || !preg_match('/^\d{6}$/', trim($code))) {
            $this->db->prepare('INSERT INTO kiosk_attempts (address_hash, at) VALUES (?, ?)')->execute([$addressHash, $now]);
            return null;
        }
        $this->db->prepare('DELETE FROM kiosk_codes WHERE code_hash = ?')->execute([self::hash(trim($code))]);

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = bin2hex(random_bytes(6));
        $name = mb_substr(trim($name), 0, 60) ?: 'Kiosk';
        $this->db->prepare('INSERT INTO kiosk_devices (id, token_hash, person, name, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, self::hash($token), (string) $person, $name, $now, $now]);
        return ['token' => $token, 'id' => $id, 'person' => (string) $person];
    }

    /** @return array{id: string, person: string, name: string}|null the device behind a secret; notes that it was seen */
    public function deviceFor(string $token): ?array
    {
        if (!preg_match(self::TOKEN, $token)) {
            return null;
        }
        $statement = $this->db->prepare('SELECT id, person, name FROM kiosk_devices WHERE token_hash = ?');
        $statement->execute([self::hash($token)]);
        $device = $statement->fetch(PDO::FETCH_ASSOC);
        if ($device === false) {
            return null;
        }
        $this->db->prepare('UPDATE kiosk_devices SET last_seen = ? WHERE id = ?')->execute([$this->now(), $device['id']]);
        return ['id' => (string) $device['id'], 'person' => (string) $device['person'], 'name' => (string) $device['name']];
    }

    /** @return list<array{id: string, name: string, createdAt: int, lastSeen: int}> devices paired by $person */
    public function list(string $person): array
    {
        $statement = $this->db->prepare('SELECT id, name, created_at, last_seen FROM kiosk_devices WHERE person = ? ORDER BY created_at');
        $statement->execute([$person]);
        return array_map(
            static fn (array $d): array => ['id' => (string) $d['id'], 'name' => (string) $d['name'], 'createdAt' => (int) $d['created_at'], 'lastSeen' => (int) $d['last_seen']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function revoke(string $person, string $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM kiosk_devices WHERE person = ? AND id = ?');
        $statement->execute([$person, $id]);
        return $statement->rowCount() > 0;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
