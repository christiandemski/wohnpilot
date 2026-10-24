<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

final readonly class NoteInfo
{
    /** @param int $modified Unix timestamp of the last change */
    public function __construct(
        public string $path,
        public string $etag,
        public int $modified,
    ) {
    }
}
