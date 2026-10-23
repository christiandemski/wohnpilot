<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

final class NotFoundException extends StorageException
{
    public static function forPath(string $path): self
    {
        return new self("Note not found: $path");
    }
}
