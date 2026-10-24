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

    /** @return list<StoredNote> */
    public function all(): array
    {
        return $this->index->all();
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

    /**
     * Writes changed frontmatter fields with a three-way merge (see FieldMerge):
     * starts from the version the client saw if it is still current, else from
     * the newest one, and sets `geaendert_von`.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     * The text below the frontmatter can change in the same write: $text is
     * ['mine' => new text, 'original' => the text the client saw]. It
     * conflicts only if it changed elsewhere too (field "text").
     *
     * @param (callable(StoredNote): array)|null $describe adds the current data to a conflict answer
     * @param array{mine: string, original: string}|null $text
     * @throws AppException conflict, with the conflicting fields
     */
    public function updateFields(StoredNote $stored, array $changes, array $original, string $etag, string $by, ?callable $describe = null, ?array $text = null): StoredNote
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $current = $attempt === 0 && $stored->etag === $etag ? $stored : $this->load($stored->path);
            $note = NoteDocument::parse($current->content);
            $conflicts = FieldMerge::apply($note->frontmatter(), $changes, $original);
            if ($text !== null) {
                $theirs = $note->body()->toString();
                $same = static fn (string $a, string $b): bool => rtrim($a) === rtrim($b);
                if (!$same($theirs, $text['mine']) && !$same($theirs, $text['original'])) {
                    $conflicts['text'] = ['mine' => $text['mine'], 'theirs' => $theirs];
                } elseif (!$same($theirs, $text['mine'])) {
                    $note = self::withBody($note, $text['mine']);
                }
            }
            if ($conflicts !== []) {
                $details = ['fields' => $conflicts] + ($describe === null ? [] : ['current' => $describe($current)]);
                throw new AppException('conflict', 409, 'Changed elsewhere', $details);
            }
            $note->frontmatter()->set('geaendert_von', $by);
            try {
                return $this->update($current->path, $note, $current->etag);
            } catch (ConflictException) {
                continue;
            }
        }
        throw new AppException('conflict', 409, 'Changed elsewhere');
    }

    /** The same note with another text below the frontmatter (ending with a line break). */
    public static function withBody(NoteDocument $note, string $body): NoteDocument
    {
        $all = $note->toString();
        $head = substr($all, 0, strlen($all) - strlen($note->body()->toString()));
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = $note->eol() === "\n" ? $body : str_replace("\n", $note->eol(), $body);
        $body = rtrim($body) === '' ? '' : rtrim($body) . $note->eol();
        return NoteDocument::parse($head . $body);
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
