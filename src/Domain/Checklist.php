<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\Lines;

/**
 * A checklist as Markdown tasks (`- [ ] Endstände ablesen`), grouped by the
 * headings above them, so it can be ticked off in the Notes app as well.
 * Only the box of one task line is ever changed; everything else stays.
 */
final class Checklist
{
    private const TASK = '/^(\s*[-*+]\s+\[)([ xX])(\]\s+)(.*)$/';
    private const HEADING = '/^#{1,6}\s+(.+?)\s*#*$/';

    /**
     * @return list<array{title: ?string, items: list<array{index: int, text: string, done: bool}>}>
     *         sections in order; tasks before the first heading have no title
     */
    public static function sections(string $note): array
    {
        $sections = [];
        $current = ['title' => null, 'items' => []];
        $index = 0;
        foreach (self::bodyLines($note) as $line) {
            $content = Lines::content($line);
            if (preg_match(self::HEADING, $content, $m)) {
                if ($current['items'] !== [] || $current['title'] !== null) {
                    $sections[] = $current;
                }
                $current = ['title' => $m[1], 'items' => []];
            } elseif (preg_match(self::TASK, $content, $m)) {
                $current['items'][] = ['index' => $index++, 'text' => trim($m[4]), 'done' => $m[2] !== ' '];
            }
        }
        if ($current['items'] !== [] || $current['title'] !== null) {
            $sections[] = $current;
        }
        // Headings without tasks (e.g. "## Notizen") are left out
        return array_values(array_filter($sections, static fn (array $s): bool => $s['items'] !== []));
    }

    /** @return array{done: int, total: int} */
    public static function progress(string $note): array
    {
        $done = 0;
        $total = 0;
        foreach (self::sections($note) as $section) {
            foreach ($section['items'] as $item) {
                $total++;
                $done += $item['done'] ? 1 : 0;
            }
        }
        return ['done' => $done, 'total' => $total];
    }

    /**
     * The note with task $index ticked or not. Null if that task is not there
     * or has another text by now (changed in the Notes app meanwhile).
     */
    public static function tick(string $note, int $index, string $text, bool $done): ?string
    {
        $lines = Lines::split($note);
        $start = self::bodyStart($lines);
        $seen = 0;
        for ($i = $start, $count = count($lines); $i < $count; $i++) {
            $content = Lines::content($lines[$i]);
            if (!preg_match(self::TASK, $content, $m)) {
                continue;
            }
            if ($seen++ !== $index) {
                continue;
            }
            if (trim($m[4]) !== trim($text)) {
                return null;
            }
            if (($m[2] !== ' ') === $done) {
                return $note;
            }
            $lines[$i] = $m[1] . ($done ? 'x' : ' ') . $m[3] . $m[4] . Lines::terminator($lines[$i]);
            return implode('', $lines);
        }
        return null;
    }

    /** @return list<string> */
    private static function bodyLines(string $note): array
    {
        $lines = Lines::split($note);
        return array_slice($lines, self::bodyStart($lines));
    }

    /** @param list<string> $lines */
    private static function bodyStart(array $lines): int
    {
        if ($lines === [] || Lines::content($lines[0]) !== '---') {
            return 0;
        }
        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            if (in_array(Lines::content($lines[$i]), ['---', '...'], true)) {
                return $i + 1;
            }
        }
        return 0;
    }
}
