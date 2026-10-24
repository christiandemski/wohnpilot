<?php

declare(strict_types=1);

namespace Wohnpilot\Index;

use PDO;
use Throwable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Storage\StoredNote;

/**
 * SQLite cache of all notes with their parsed frontmatter.
 *
 * Only a cache: it can be deleted at any time and is rebuilt from storage
 * by sync(). Notes whose ETag did not change are not fetched again.
 */
final class NoteIndex
{
    private const SCHEMA_VERSION = 1;

    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    public static function open(string $file): self
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        return new self(new PDO('sqlite:' . $file));
    }

    /** @return array{fetched: int, removed: int, unchanged: int} */
    public function sync(StorageAdapter $storage): array
    {
        $known = $this->db->query('SELECT path, etag FROM notes')->fetchAll(PDO::FETCH_KEY_PAIR);
        $stats = ['fetched' => 0, 'removed' => 0, 'unchanged' => 0];
        $seen = [];
        foreach ($storage->list() as $info) {
            $seen[$info->path] = true;
            if (($known[$info->path] ?? null) === $info->etag) {
                $stats['unchanged']++;
                continue;
            }
            $this->put($storage->get($info->path));
            $stats['fetched']++;
        }
        foreach (array_keys($known) as $path) {
            if (!isset($seen[$path])) {
                $this->db->prepare('DELETE FROM notes WHERE path = ?')->execute([$path]);
                $stats['removed']++;
            }
        }
        $this->setMeta('synced_at', (string) time());
        return $stats;
    }

    /** Drops everything and syncs from scratch. */
    public function rebuild(StorageAdapter $storage): array
    {
        $this->db->exec('DELETE FROM notes');
        return $this->sync($storage);
    }

    /** Syncs if the last sync is older than $maxAgeSeconds. */
    public function syncIfStale(StorageAdapter $storage, int $maxAgeSeconds): void
    {
        $syncedAt = (int) ($this->meta('synced_at') ?? 0);
        if (time() - $syncedAt >= $maxAgeSeconds) {
            $this->sync($storage);
        }
    }

    /** Stores one note, e.g. right after the app wrote it. */
    public function put(StoredNote $note): void
    {
        $frontmatter = [];
        try {
            $frontmatter = NoteDocument::parse($note->content)->frontmatter()->all();
        } catch (Throwable) {
            // Broken YAML from a manual edit: keep the note, without fields.
        }
        $this->db->prepare(
            'INSERT INTO notes (path, etag, modified, typ, id, objekt, frontmatter, content)
             VALUES (:path, :etag, :modified, :typ, :id, :objekt, :frontmatter, :content)
             ON CONFLICT(path) DO UPDATE SET etag = excluded.etag, modified = excluded.modified, typ = excluded.typ,
               id = excluded.id, objekt = excluded.objekt, frontmatter = excluded.frontmatter, content = excluded.content',
        )->execute([
            'path' => $note->path,
            'etag' => $note->etag,
            'modified' => $note->modified,
            'typ' => is_scalar($frontmatter['typ'] ?? null) ? (string) $frontmatter['typ'] : null,
            'id' => is_scalar($frontmatter['id'] ?? null) ? (string) $frontmatter['id'] : null,
            'objekt' => is_scalar($frontmatter['objekt'] ?? null) ? (string) $frontmatter['objekt'] : null,
            'frontmatter' => json_encode($frontmatter, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'content' => $note->content,
        ]);
    }

    public function get(string $path): ?StoredNote
    {
        $statement = $this->db->prepare('SELECT * FROM notes WHERE path = ?');
        $statement->execute([$path]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::toStored($row);
    }

    /** @return list<StoredNote> notes of a type, optionally of one object, ordered by path */
    public function byType(string $typ, ?string $objekt = null): array
    {
        $sql = 'SELECT * FROM notes WHERE typ = ?' . ($objekt === null ? '' : ' AND objekt = ?') . ' ORDER BY path';
        $statement = $this->db->prepare($sql);
        $statement->execute($objekt === null ? [$typ] : [$typ, $objekt]);
        return array_map(self::toStored(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<StoredNote> every note, ordered by path (search, "where is this contact used") */
    public function all(): array
    {
        return array_map(self::toStored(...), $this->db->query('SELECT * FROM notes ORDER BY path')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(string $typ, string $id): ?StoredNote
    {
        $statement = $this->db->prepare('SELECT * FROM notes WHERE typ = ? AND id = ? ORDER BY path LIMIT 1');
        $statement->execute([$typ, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::toStored($row);
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM notes')->fetchColumn();
    }

    private static function toStored(array $row): StoredNote
    {
        return new StoredNote($row['path'], $row['content'], $row['etag'], (int) $row['modified']);
    }

    private function meta(string $key): ?string
    {
        $statement = $this->db->prepare('SELECT value FROM meta WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function setMeta(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        if ((int) ($this->meta('schema') ?? 0) === self::SCHEMA_VERSION) {
            return;
        }
        // A cache can simply start over when its layout changes.
        $this->db->exec('DROP TABLE IF EXISTS notes');
        $this->db->exec(
            'CREATE TABLE notes (
                path TEXT PRIMARY KEY,
                etag TEXT NOT NULL,
                modified INTEGER NOT NULL,
                typ TEXT,
                id TEXT,
                objekt TEXT,
                frontmatter TEXT NOT NULL,
                content TEXT NOT NULL
            )',
        );
        $this->db->exec('CREATE INDEX notes_typ ON notes (typ, objekt)');
        $this->setMeta('schema', (string) self::SCHEMA_VERSION);
        $this->setMeta('synced_at', '0');
    }
}
