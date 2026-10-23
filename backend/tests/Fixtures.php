<?php

declare(strict_types=1);

namespace Wohnpilot\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Fixtures
{
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/Wohnpilot';
    }

    public static function read(string $relativePath): string
    {
        return file_get_contents(self::root() . '/' . $relativePath);
    }

    /** @return array<string, array{string}> relative path => [relative path] */
    public static function allNotes(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'md') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1));
                $files[$relative] = [$relative];
            }
        }
        ksort($files);
        return $files;
    }
}
