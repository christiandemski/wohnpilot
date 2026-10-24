<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A meter read from its note. Readings are ordered oldest first; on the
 * same day the table order decides (the upper row is the later one).
 */
final readonly class Meter
{
    public const COLUMNS = ['Datum', 'Stand', 'Anlass', 'Foto', 'Von'];

    /** @param list<Reading> $readings oldest first */
    public function __construct(
        public string $id,
        public string $medium,
        public string $unit,
        public int $digits,
        public int $decimals,
        public string $room,
        public string $interval,
        public ?Tariff $tariff,
        public array $readings,
        public string $object = '',
        public string $number = '',
        public string $name = '',
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $readings = [];
        $table = $note->body()->table();
        foreach ($table?->rows() ?? [] as $row) {
            $date = trim($row['Datum'] ?? '');
            $value = Reading::parseNumber($row['Stand'] ?? '');
            if ($value === null || !Day::isValid($date)) {
                continue; // a hand-edited row we cannot read stays in the note but is not counted
            }
            $readings[] = new Reading($date, $value, $row['Anlass'] ?? '', $row['Foto'] ?? '', $row['Von'] ?? '');
        }
        $readings = array_reverse($readings);
        usort($readings, static fn (Reading $a, Reading $b): int => strcmp($a->date, $b->date));

        $tarif = $fm->get('tarif');

        return new self(
            id: (string) $fm->get('id', ''),
            medium: (string) $fm->get('medium', ''),
            unit: (string) $fm->get('einheit', ''),
            digits: (int) $fm->get('stellen', 6),
            decimals: (int) $fm->get('nachkommastellen', 0),
            room: (string) $fm->get('raum', ''),
            interval: (string) $fm->get('ablese_intervall', 'monatlich'),
            tariff: is_array($tarif) ? Tariff::fromArray($tarif) : null,
            readings: $readings,
            object: (string) $fm->get('objekt', ''),
            number: (string) $fm->get('zaehlernummer', ''),
            name: (string) $fm->get('bezeichnung', ''),
        );
    }

    public function latest(): ?Reading
    {
        return $this->readings === [] ? null : $this->readings[count($this->readings) - 1];
    }
}
