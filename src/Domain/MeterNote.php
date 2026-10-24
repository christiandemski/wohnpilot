<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use InvalidArgumentException;
use Wohnpilot\Note\NoteDocument;

/** Writes meter data into a meter note without touching anything else. */
final class MeterNote
{
    /**
     * Adds a reading as a new table row at its chronological place (newest on
     * top) and sets `geaendert_von`. Creates the table if the note has none,
     * which is why the (possibly new) document is returned.
     */
    public static function addReading(NoteDocument $note, Reading $reading, string $by): NoteDocument
    {
        if (!Day::isValid($reading->date)) {
            throw new InvalidArgumentException("Invalid reading date: {$reading->date}");
        }
        $decimals = (int) $note->frontmatter()->get('nachkommastellen', 0);
        $table = $note->body()->table();
        if ($table === null) {
            $note = self::withEmptyTable($note);
            $table = $note->body()->table();
        }

        // Newest first: insert above the first row that is not newer than the new reading.
        $position = 0;
        foreach ($table->rows() as $index => $row) {
            if (trim($row['Datum'] ?? '') > $reading->date) {
                $position = $index + 1;
            }
        }

        $values = array_intersect_key([
            'Datum' => $reading->date,
            'Stand' => Reading::formatNumber($reading->value, $decimals),
            'Anlass' => $reading->occasion,
            'Foto' => $reading->photo,
            'Von' => $by,
        ], array_flip($table->headers()));
        $table->insertRow($values, $position);
        $note->frontmatter()->set('geaendert_von', $by);
        return $note;
    }

    /** Puts an empty reading table in front of the body text. */
    public static function withEmptyTable(NoteDocument $note): NoteDocument
    {
        if ($note->body()->table() !== null) {
            return $note;
        }
        $eol = $note->eol();
        $header = '| Datum      | Stand | Anlass  | Foto | Von |' . $eol . '|------------|-------|---------|------|-----|' . $eol;
        $text = $note->toString();
        $bodyText = $note->body()->toString();
        $head = substr($text, 0, strlen($text) - strlen($bodyText));
        $separator = $bodyText === '' ? '' : $eol;
        return NoteDocument::parse($head . $header . $separator . $bodyText);
    }
}
