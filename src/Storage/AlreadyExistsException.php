<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

final class AlreadyExistsException extends StorageException
{
    public static function forPath(string $path): self
    {
        return new self("Note already exists: $path");
    }
}
