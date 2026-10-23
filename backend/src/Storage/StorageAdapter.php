<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

/**
 * Where notes live. Paths are relative to the Wohnpilot root folder, e.g.
 * "objekte/efh-musterstrasse/zaehler/strom-haupt.md" (see NotePath).
 *
 * Every write names the version it is based on; a write against a newer
 * version fails with ConflictException instead of overwriting it.
 */
interface StorageAdapter
{
    /**
     * All notes below the root, without content.
     *
     * @return list<NoteInfo>
     */
    public function list(): array;

    /** @throws NotFoundException */
    public function get(string $path): StoredNote;

    /** @throws AlreadyExistsException if a note with that path exists */
    public function create(string $path, string $content): StoredNote;

    /**
     * @param string $etag the version the new content is based on
     * @throws ConflictException if the note changed since $etag
     * @throws NotFoundException
     */
    public function update(string $path, string $content, string $etag): StoredNote;
}
