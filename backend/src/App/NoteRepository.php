<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\ConflictException;
use Wohnpilot\Storage\NotFoundException;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Storage\StoredNote;

/**
 * Reads through the index, writes to storage first and then updates the index.
 */
final class NoteRepository
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly StorageAdapter $storage,
        private readonly NoteIndex $index,
        private readonly int $syncMaxAgeSeconds = 30,
    ) {
    }

    public function refresh(bool $force = false): void
    {
        $force ? $this->index->sync($this->storage) : $this->index->syncIfStale($this->storage, $this->syncMaxAgeSeconds);
    }

    public function findById(string $typ, string $id): ?StoredNote
    {
        return $this->index->findById($typ, $id);
    }

    /** @return list<StoredNote> */
    public function byType(string $typ, ?string $objekt = null): array
    {
        return $this->index->byType($typ, $objekt);
    }

    public function exists(string $path): bool
    {
        return $this->index->get($path) !== null;
    }

    public function create(string $path, NoteDocument $note): StoredNote
    {
        $stored = $this->storage->create($path, $note->toString());
        $this->index->put($stored);
        return $stored;
    }

    /** @throws ConflictException if the note changed since $etag */
    public function update(string $path, NoteDocument $note, string $etag): StoredNote
    {
        try {
            $stored = $this->storage->update($path, $note->toString(), $etag);
        } catch (ConflictException $e) {
            $this->index->put($e->current);
            throw $e;
        }
        $this->index->put($stored);
        return $stored;
    }

    /**
     * Applies $change to the newest version of the note and writes it with
     * that version's ETag. Used for changes that merge safely, like adding a
     * reading: someone else's edit is kept, never overwritten.
     *
     * @param callable(NoteDocument): NoteDocument $change
     */
    public function modifyLatest(string $path, callable $change): StoredNote
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $current = $this->storage->get($path);
            } catch (NotFoundException) {
                throw AppException::notFound($path);
            }
            $changed = $change(NoteDocument::parse($current->content));
            if ($changed->toString() === $current->content) {
                $this->index->put($current);
                return $current;
            }
            try {
                return $this->update($path, $changed, $current->etag);
            } catch (ConflictException $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /** Fresh version from storage, bypassing the cache. */
    public function load(string $path): StoredNote
    {
        try {
            $stored = $this->storage->get($path);
        } catch (NotFoundException) {
            throw AppException::notFound($path);
        }
        $this->index->put($stored);
        return $stored;
    }
}
