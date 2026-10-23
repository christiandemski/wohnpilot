<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

final readonly class StoredNote
{
    public function __construct(
        public string $path,
        public string $content,
        public string $etag,
        public int $modified,
    ) {
    }

    public function info(): NoteInfo
    {
        return new NoteInfo($this->path, $this->etag, $this->modified);
    }
}
