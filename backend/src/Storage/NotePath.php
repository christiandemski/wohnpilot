<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

use InvalidArgumentException;

/**
 * Relative note paths like "objekte/efh-musterstrasse/zaehler/strom-haupt.md".
 * Segments are slugs (or start with "_" like "_objekt.md"); nothing can
 * escape the root folder.
 */
final class NotePath
{
    private const SEGMENT = '/^_?[a-z0-9][a-z0-9_-]*$/';

    public static function assertValid(string $path): void
    {
        if (!str_ends_with($path, '.md')) {
            throw new InvalidArgumentException("Note path must end with .md: $path");
        }
        foreach (explode('/', substr($path, 0, -3)) as $segment) {
            if (!preg_match(self::SEGMENT, $segment)) {
                throw new InvalidArgumentException("Invalid note path: $path");
            }
        }
    }

    public static function isValid(string $path): bool
    {
        try {
            self::assertValid($path);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** "objekte/x/zaehler/strom.md" → "objekte/x/zaehler" ("" at the root) */
    public static function folder(string $path): string
    {
        $slash = strrpos($path, '/');
        return $slash === false ? '' : substr($path, 0, $slash);
    }

    /** "objekte/x/zaehler/strom.md" → "strom" */
    public static function name(string $path): string
    {
        $slash = strrpos($path, '/');
        return substr($path, $slash === false ? 0 : $slash + 1, -3);
    }

    public static function join(string $folder, string $name): string
    {
        return ($folder === '' ? '' : $folder . '/') . $name . '.md';
    }
}
