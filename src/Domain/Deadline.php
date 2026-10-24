<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A one-off deadline (`typ: frist`), e.g. a meter reading requested by the supplier.
 *
 * It is done when `erledigt` holds a date, or – if it refers to meters – as soon as
 * every one of them has a reading on or after the start of the window (`ab`, else
 * DEFAULT_WINDOW_DAYS before the date). Late readings count too.
 */
final readonly class Deadline
{
    public const DEFAULT_LEAD_DAYS = 14;
    public const DEFAULT_WINDOW_DAYS = 30;

    public const DONE = 'done';

    /** @param list<string> $refersTo ids of meters, contracts, … */
    public function __construct(
        public string $id,
        public string $title,
        public string $date,
        public ?string $from = null,
        public int $leadDays = self::DEFAULT_LEAD_DAYS,
        public array $refersTo = [],
        public ?string $done = null,
        public string $object = '',
    ) {
    }

    /** Null if the note has no valid `datum`. */
    public static function fromNote(NoteDocument $note): ?self
    {
        $fm = $note->frontmatter();
        $date = static fn (string $key): ?string => is_string($v = $fm->get($key)) && Day::isValid($v) ? $v : null;
        if ($date('datum') === null) {
            return null;
        }
        $refersTo = $fm->get('bezieht_sich_auf', []);
        return new self(
            id: (string) $fm->get('id', ''),
            title: (string) $fm->get('titel', $fm->get('id', '')),
            date: $date('datum'),
            from: $date('ab'),
            leadDays: is_numeric($fm->get('vorlauf_tage')) ? max(0, (int) $fm->get('vorlauf_tage')) : self::DEFAULT_LEAD_DAYS,
            refersTo: is_array($refersTo) ? array_values(array_map('strval', $refersTo)) : [(string) $refersTo],
            done: $date('erledigt'),
            object: (string) $fm->get('objekt', ''),
        );
    }

    public function windowStart(): string
    {
        return $this->from ?? Day::addDays($this->date, -self::DEFAULT_WINDOW_DAYS);
    }

    /**
     * @param array<string, Meter> $meters by id; ids that are no meter are ignored
     * @return array{status: string, daysUntil: int, doneOn: ?string, doneBy: ?string, meters: list<array{id: string, readOn: ?string}>}
     */
    public function evaluate(string $today, array $meters = []): array
    {
        $linked = [];
        foreach ($this->refersTo as $id) {
            if (isset($meters[$id])) {
                $linked[] = ['id' => $id, 'readOn' => $this->firstReadingInWindow($meters[$id])];
            }
        }

        $doneOn = $this->done;
        $doneBy = $doneOn === null ? null : 'manual';
        $readDates = array_column($linked, 'readOn');
        if ($doneOn === null && $linked !== [] && !in_array(null, $readDates, true)) {
            $doneOn = max($readDates);
            $doneBy = 'readings';
        }

        $days = Day::between($today, $this->date);
        return [
            'status' => $doneOn !== null ? self::DONE : Urgency::of($days, $this->leadDays),
            'daysUntil' => $days,
            'doneOn' => $doneOn,
            'doneBy' => $doneBy,
            'meters' => $linked,
        ];
    }

    private function firstReadingInWindow(Meter $meter): ?string
    {
        foreach ($meter->readings as $reading) { // oldest first
            if ($reading->date >= $this->windowStart()) {
                return $reading->date;
            }
        }
        return null;
    }
}
