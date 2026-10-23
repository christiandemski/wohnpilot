<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

/**
 * Line helpers that keep line terminators attached, so text can be split
 * and joined again without losing a single byte.
 */
final class Lines
{
    /** @return list<string> lines including their terminator; the last one may have none */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        return preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function detectEol(string $text): string
    {
        return str_contains($text, "\r\n") ? "\r\n" : "\n";
    }

    /** The line content without its terminator. */
    public static function content(string $line): string
    {
        return rtrim($line, "\r\n");
    }

    /** The terminator of the line ("", "\n" or "\r\n"). */
    public static function terminator(string $line): string
    {
        return substr($line, strlen(self::content($line)));
    }
}
