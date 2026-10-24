<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

/**
 * Photos of a note are Markdown images in its text, with paths relative to
 * the note (so the Notes app shows them too):
 * `![Typenschild](../../../anhaenge/geraete/waschmaschine/typenschild.jpg)`.
 * Images pasted in the Notes app (`.attachments.123/foto.jpg`) count as well.
 */
final class NoteImages
{
    /** ![alt](src "title"), groups 1 = alt, 2 = src; without delimiters to build patterns from */
    private const IMAGE = '!\[([^\]\n]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)';

    /**
     * @return list<array{alt: string, src: string, path: string}> path: relative to the Wohnpilot root;
     *   external images and paths leaving the root are skipped
     */
    public static function in(string $text, string $noteFolder): array
    {
        preg_match_all('/' . self::IMAGE . '/u', $text, $matches, PREG_SET_ORDER);
        $images = [];
        foreach ($matches as [, $alt, $src]) {
            $path = self::resolve($noteFolder, rawurldecode($src));
            if ($path !== null) {
                $images[] = ['alt' => $alt, 'src' => $src, 'path' => $path];
            }
        }
        return $images;
    }

    /** "objekte/x/maengel" + "../../../anhaenge/a.jpg" → "anhaenge/a.jpg"; null for URLs and paths outside the root. */
    public static function resolve(string $noteFolder, string $src): ?string
    {
        if ($src === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $src)) {
            return null;
        }
        $parts = $noteFolder === '' ? [] : explode('/', $noteFolder);
        foreach (explode('/', $src) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return $parts === [] ? null : implode('/', $parts);
    }

    /** The way from the note's folder to $path: "objekte/x/maengel" → "../../../anhaenge/a.jpg". */
    public static function relative(string $noteFolder, string $path): string
    {
        $depth = $noteFolder === '' ? 0 : count(explode('/', $noteFolder));
        return str_repeat('../', $depth) . $path;
    }

    /** Adds an image line at the end of the note text, after an empty line. */
    public static function append(NoteDocument $note, string $alt, string $src): NoteDocument
    {
        $eol = $note->eol();
        $text = $note->toString();
        // Brackets would end the alt text early, line breaks would break the image.
        $alt = trim((string) preg_replace('/[\[\]\s]+/u', ' ', $alt));
        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= $eol;
        }
        $lines = preg_split('/\r?\n/', rtrim($text, "\r\n"));
        $last = end($lines);
        // A blank line before the image, unless the note ends with the frontmatter or an image already.
        $separator = $last === '---' || preg_match('/^' . self::IMAGE . '$/u', trim((string) $last)) ? '' : $eol;
        return NoteDocument::parse($text . $separator . "![$alt]($src)" . $eol);
    }

    /** Removes lines that are only this image; blank lines left at the end go too. */
    public static function remove(NoteDocument $note, string $path, string $noteFolder): NoteDocument
    {
        $lines = preg_split('/(?<=\n)/', $note->toString());
        $kept = [];
        foreach ($lines as $line) {
            $content = trim($line);
            if ($content !== '' && preg_match('/^' . self::IMAGE . '$/u', $content, $m) && self::resolve($noteFolder, rawurldecode($m[2])) === $path) {
                continue;
            }
            $kept[] = $line;
        }
        $text = implode('', $kept);
        $text = preg_replace('/(\r?\n)(?:\r?\n)+$/', '$1', $text) ?? $text;
        return NoteDocument::parse($text);
    }
}
