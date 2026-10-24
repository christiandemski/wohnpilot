<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

use InvalidArgumentException;

/** Checks attachment paths: relative, no "..", an image extension. */
final class AttachmentPath
{
    public static function assertReadable(string $path): void
    {
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1f\\\\:*?"<>|]/', $segment)) {
                throw new InvalidArgumentException("Invalid attachment path: $path");
            }
        }
        if (self::type($path) === null) {
            throw new InvalidArgumentException("Not an image: $path");
        }
    }

    /** Wohnpilot's own files: below anhaenge/, slugs only. */
    public static function assertWritable(string $path): void
    {
        self::assertReadable($path);
        if (!preg_match('#^' . AttachmentStore::FOLDER . '(/[a-z0-9][a-z0-9_-]*)+\.(jpg|jpeg|png|webp)$#', $path)) {
            throw new InvalidArgumentException("Wohnpilot writes below anhaenge/ only: $path");
        }
    }

    /** The media type by extension, or null if it is no image. */
    public static function type(string $path): ?string
    {
        return AttachmentStore::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }
}
