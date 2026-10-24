<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

/**
 * The Markdown text after the frontmatter, split into plain text segments
 * and tables. Plain text is never touched.
 */
final class Body
{
    /** @var list<string|MarkdownTable> */
    private array $segments = [];

    public function __construct(string $text, private readonly string $eol = "\n")
    {
        $lines = Lines::split($text);
        $plain = '';
        $i = 0;
        $count = count($lines);
        while ($i < $count) {
            $content = Lines::content($lines[$i]);
            $next = $i + 1 < $count ? Lines::content($lines[$i + 1]) : null;
            if (MarkdownTable::isRow($content) && $next !== null && MarkdownTable::isSeparator($next)) {
                if ($plain !== '') {
                    $this->segments[] = $plain;
                    $plain = '';
                }
                $tableLines = [$lines[$i], $lines[$i + 1]];
                $i += 2;
                while ($i < $count && MarkdownTable::isRow(Lines::content($lines[$i]))) {
                    $tableLines[] = $lines[$i];
                    $i++;
                }
                $this->segments[] = MarkdownTable::fromLines($tableLines, $this->eol);
                continue;
            }
            $plain .= $lines[$i];
            $i++;
        }
        if ($plain !== '') {
            $this->segments[] = $plain;
        }
    }

    /** @return list<MarkdownTable> */
    public function tables(): array
    {
        return array_values(array_filter($this->segments, static fn ($s) => $s instanceof MarkdownTable));
    }

    public function table(int $index = 0): ?MarkdownTable
    {
        return $this->tables()[$index] ?? null;
    }

    /** All text outside of tables, e.g. the free notes below a reading history. */
    public function text(): string
    {
        return implode('', array_filter($this->segments, 'is_string'));
    }

    public function toString(): string
    {
        $out = '';
        foreach ($this->segments as $segment) {
            $out .= is_string($segment) ? $segment : $segment->toString();
        }
        return $out;
    }
}
