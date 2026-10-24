<?php

declare(strict_types=1);

namespace Wohnpilot\Http;

use PDO;

/**
 * Answers to writes that carried an `Idempotency-Key` header, kept for a
 * week. The offline queue sends each entry with its own key; if the answer
 * got lost on the way and the entry is sent again, the first answer comes
 * back instead of a second defect or a second photo.
 */
final class IdempotencyStore
{
    public const KEEP_SECONDS = 7 * 86400;

    /** @param \Closure(): int|null $clock seconds since epoch, replaceable in tests */
    public function __construct(private readonly PDO $db, private readonly ?\Closure $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS idempotency (
                key TEXT PRIMARY KEY,
                status INTEGER NOT NULL,
                content_type TEXT NOT NULL,
                body TEXT NOT NULL,
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

    /** @return array{status: int, contentType: string, body: string}|null */
    public function find(string $key): ?array
    {
        $statement = $this->db->prepare('SELECT status, content_type, body FROM idempotency WHERE key = ? AND created_at > ?');
        $statement->execute([$key, $this->now() - self::KEEP_SECONDS]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['status' => (int) $row['status'], 'contentType' => (string) $row['content_type'], 'body' => (string) $row['body']];
    }

    public function save(string $key, int $status, string $contentType, string $body): void
    {
        $now = $this->now();
        $this->db->prepare('DELETE FROM idempotency WHERE created_at <= ?')->execute([$now - self::KEEP_SECONDS]);
        $this->db->prepare('INSERT OR REPLACE INTO idempotency (key, status, content_type, body, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$key, $status, $contentType, $body, $now]);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
