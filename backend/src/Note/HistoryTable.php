<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

/**
 * History tables in notes (readings, prices, rent): newest row on top,
 * created in front of the body text when a note has none yet.
 */
final class HistoryTable
{
    /**
     * Returns the note with a table whose header has $columns; an existing
     * table is kept as it is. A new note document may be returned. Columns
     * are as wide as the $first row that will go in, so it lines up.
     *
     * @param list<string> $columns
     * @param array<string, string> $first
     */
    public static function ensure(NoteDocument $note, array $columns, array $first = []): NoteDocument
    {
        if ($note->body()->table() !== null) {
            return $note;
        }
        $eol = $note->eol();
        $header = '|';
        $rule = '|';
        foreach ($columns as $column) {
            $width = max(mb_strlen($column), mb_strlen($first[$column] ?? ''));
            $header .= ' ' . $column . str_repeat(' ', $width - mb_strlen($column)) . ' |';
            $rule .= str_repeat('-', $width + 2) . '|';
        }
        $header .= $eol . $rule . $eol;
        $text = $note->toString();
        $bodyText = $note->body()->toString();
        $head = substr($text, 0, strlen($text) - strlen($bodyText));
        $separator = $bodyText === '' ? '' : $eol;
        return NoteDocument::parse($head . $header . $separator . $bodyText);
    }

    /**
     * Inserts a row above the first row that is not newer, by the ISO date in
     * $dateColumn. Values for columns the table does not have are dropped.
     *
     * @param array<string, string> $values
     */
    public static function insertNewestFirst(MarkdownTable $table, string $dateColumn, array $values): void
    {
        $date = $values[$dateColumn] ?? '';
        $position = 0;
        foreach ($table->rows() as $index => $row) {
            if (trim($row[$dateColumn] ?? '') > $date) {
                $position = $index + 1;
            }
        }
        $table->insertRow(array_intersect_key($values, array_flip($table->headers())), $position);
    }

    /** Index of the first row whose $column is $value, or null. */
    public static function find(MarkdownTable $table, string $column, string $value): ?int
    {
        foreach ($table->rows() as $index => $row) {
            if (trim($row[$column] ?? '') === $value) {
                return $index;
            }
        }
        return null;
    }
}
