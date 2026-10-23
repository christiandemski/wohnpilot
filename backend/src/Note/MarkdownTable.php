<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * A pipe table inside a note body, kept as raw lines.
 *
 * Existing rows are never reformatted; new rows are padded to the column
 * widths of the separator line so the table stays readable in a plain editor.
 */
final class MarkdownTable
{
    private const SEPARATOR = '/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/';

    /** @var list<string> */
    private array $rowLines;

    /** @var list<string> */
    private readonly array $headers;

    /** @param list<string> $rowLines */
    private function __construct(
        private string $headerLine,
        private string $separatorLine,
        array $rowLines,
        private readonly string $eol,
    ) {
        $this->rowLines = $rowLines;
        $this->headers = self::splitCells($headerLine);
    }

    public static function isSeparator(string $content): bool
    {
        return str_contains($content, '-') && preg_match(self::SEPARATOR, $content) === 1;
    }

    public static function isRow(string $content): bool
    {
        return str_starts_with(ltrim($content), '|');
    }

    /** @param list<string> $lines header, separator and row lines with terminators */
    public static function fromLines(array $lines, string $eol): self
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A table needs a header and a separator line.');
        }
        return new self($lines[0], $lines[1], array_slice($lines, 2), $eol);
    }

    /** @return list<string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function count(): int
    {
        return count($this->rowLines);
    }

    /**
     * Rows keyed by header text, top row first. Missing cells are "".
     *
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        return array_map($this->rowToAssoc(...), $this->rowLines);
    }

    /** @return array<string, string> */
    public function row(int $index): array
    {
        $this->assertIndex($index);
        return $this->rowToAssoc($this->rowLines[$index]);
    }

    /**
     * Inserts a row; position 0 is the top (newest entry first).
     *
     * @param array<string, string|int|float|null> $values keyed by header; unknown keys are rejected
     */
    public function insertRow(array $values, int $position = 0): void
    {
        if ($position < 0 || $position > count($this->rowLines)) {
            throw new OutOfRangeException("Row position $position is out of range.");
        }
        $line = $this->formatRow($values);
        $previous = $position === 0 ? null : $position - 1;
        $isLast = $position === count($this->rowLines);

        if ($isLast) {
            // The line before the new one might end the file without a terminator.
            $before = $previous === null ? $this->separatorLine : $this->rowLines[$previous];
            if (Lines::terminator($before) === '') {
                $before .= $this->eol;
                if ($previous === null) {
                    $this->separatorLine = $before;
                } else {
                    $this->rowLines[$previous] = $before;
                }
                array_splice($this->rowLines, $position, 0, [$line]);
                return;
            }
        }
        array_splice($this->rowLines, $position, 0, [$line . $this->eol]);
    }

    /** @param array<string, string|int|float|null> $values */
    public function replaceRow(int $index, array $values): void
    {
        $this->assertIndex($index);
        $merged = array_merge($this->row($index), $values);
        $terminator = Lines::terminator($this->rowLines[$index]);
        $line = $this->formatRow($merged);
        if (Lines::content($this->rowLines[$index]) !== $line) {
            $this->rowLines[$index] = $line . $terminator;
        }
    }

    public function removeRow(int $index): void
    {
        $this->assertIndex($index);
        $terminator = Lines::terminator($this->rowLines[$index]);
        array_splice($this->rowLines, $index, 1);
        // Keep the file ending as it was when the last row goes away.
        if ($terminator === '') {
            $lastIndex = count($this->rowLines) - 1;
            if ($lastIndex >= 0) {
                $this->rowLines[$lastIndex] = Lines::content($this->rowLines[$lastIndex]);
            } else {
                $this->separatorLine = Lines::content($this->separatorLine);
            }
        }
    }

    public function toString(): string
    {
        return $this->headerLine . $this->separatorLine . implode('', $this->rowLines);
    }

    /** @return array<string, string> */
    private function rowToAssoc(string $line): array
    {
        $cells = self::splitCells($line);
        $row = [];
        foreach ($this->headers as $i => $header) {
            $row[$header] = $cells[$i] ?? '';
        }
        return $row;
    }

    /** @param array<string, string|int|float|null> $values */
    private function formatRow(array $values): string
    {
        $unknown = array_diff(array_keys($values), $this->headers);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown table columns: ' . implode(', ', $unknown));
        }
        $widths = $this->columnWidths();
        $cells = [];
        foreach ($this->headers as $i => $header) {
            $text = str_replace(["\r", "\n"], ' ', (string) ($values[$header] ?? ''));
            $text = str_replace('|', '\\|', $text);
            $cells[] = self::pad($text, $widths[$i] ?? 0);
        }
        return '| ' . implode(' | ', $cells) . ' |';
    }

    /** @return list<int> content width per column, taken from the separator line */
    private function columnWidths(): array
    {
        $segments = self::splitRaw(Lines::content($this->separatorLine));
        return array_map(static fn (string $s): int => max(mb_strlen($s) - 2, 0), $segments);
    }

    private static function pad(string $text, int $width): string
    {
        $missing = $width - mb_strlen($text);
        return $missing > 0 ? $text . str_repeat(' ', $missing) : $text;
    }

    /** @return list<string> trimmed, unescaped cell texts */
    private static function splitCells(string $line): array
    {
        return array_map(
            static fn (string $cell): string => str_replace('\\|', '|', trim($cell)),
            self::splitRaw(Lines::content($line)),
        );
    }

    /** @return list<string> raw cell texts between unescaped pipes */
    private static function splitRaw(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '|')) {
            $content = substr($content, 1);
        }
        if (str_ends_with($content, '|') && !str_ends_with($content, '\\|')) {
            $content = substr($content, 0, -1);
        }
        return preg_split('/(?<!\\\\)\|/', $content);
    }

    private function assertIndex(int $index): void
    {
        if (!isset($this->rowLines[$index])) {
            throw new OutOfRangeException("Row $index does not exist.");
        }
    }
}
