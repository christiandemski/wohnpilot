<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

/** The note changed since the version a write was based on. Carries the current version for merging. */
final class ConflictException extends StorageException
{
    public function __construct(public readonly StoredNote $current)
    {
        parent::__construct("Note changed in the meantime: {$current->path}");
    }
}
