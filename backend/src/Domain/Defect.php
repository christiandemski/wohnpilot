<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A defect (`typ: mangel`) with its status history (newest first). The
 * current status is the top row; dates of discovery, report and repair
 * come from the history, the follow-up day from `nachfassen`.
 */
final readonly class Defect
{
    public const STATUSES = ['entdeckt', 'gemeldet', 'bestaetigt', 'termin', 'behoben'];
    public const HISTORY_COLUMNS = ['Datum', 'Status', 'Kanal', 'Notiz'];
    public const FIXED = 'behoben';
    /** Days before the follow-up day when it counts as "soon". */
    public const FOLLOW_UP_LEAD_DAYS = 3;

    /** @param list<array{date: string, status: string, channel: string, note: string}> $history newest first */
    public function __construct(
        public string $id,
        public string $title,
        public array $history,
        public string $room = '',
        public string $responsible = 'vermieter',
        public ?string $followUp = null,
        public bool $minorRepair = false,
        public ?float $cost = null,
        public string $object = '',
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $history = [];
        foreach ($note->body()->table()?->rows() ?? [] as $row) {
            $date = trim($row['Datum'] ?? '');
            if (Day::isValid($date)) {
                $history[] = ['date' => $date, 'status' => trim($row['Status'] ?? ''), 'channel' => trim($row['Kanal'] ?? ''), 'note' => trim($row['Notiz'] ?? '')];
            }
        }
        // Newest first, also if someone added a row in the wrong place by hand.
        usort($history, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);
        $followUp = $fm->get('nachfassen');
        $cost = $fm->get('kosten_eur');
        return new self(
            id: (string) $fm->get('id', ''),
            title: (string) $fm->get('titel', $fm->get('id', '')),
            history: $history,
            room: (string) $fm->get('raum', ''),
            responsible: (string) $fm->get('zustaendig', 'vermieter'),
            followUp: is_string($followUp) && Day::isValid($followUp) ? $followUp : null,
            minorRepair: $fm->get('kleinreparatur') === true,
            cost: is_int($cost) || is_float($cost) ? (float) $cost : null,
            object: (string) $fm->get('objekt', ''),
        );
    }

    public function status(): string
    {
        return $this->history[0]['status'] ?? 'entdeckt';
    }

    public function isOpen(): bool
    {
        return $this->status() !== self::FIXED;
    }

    /** First day a status was reached, or null. */
    public function dateOf(string $status): ?string
    {
        $dates = array_column(array_filter($this->history, static fn (array $h): bool => $h['status'] === $status), 'date');
        return $dates === [] ? null : min($dates);
    }

    public function discovered(): ?string
    {
        return $this->dateOf('entdeckt') ?? ($this->history === [] ? null : min(array_column($this->history, 'date')));
    }

    /** Day of the repair: the latest "behoben", if that is the current status. */
    public function fixedOn(): ?string
    {
        return $this->isOpen() ? null : $this->history[0]['date'];
    }

    /**
     * @return array{status: string, open: bool, discovered: ?string, reported: ?string, fixedOn: ?string, days: ?int,
     *   followUp: ?array{date: string, daysUntil: int, status: string}}
     *   days: how long the defect lasted or lasts until today (a basis if rent reduction comes up)
     */
    public function evaluate(string $today): array
    {
        $discovered = $this->discovered();
        $fixed = $this->fixedOn();
        $followUp = null;
        if ($this->isOpen() && $this->followUp !== null) {
            $days = Day::between($today, $this->followUp);
            $followUp = ['date' => $this->followUp, 'daysUntil' => $days, 'status' => Urgency::of($days, self::FOLLOW_UP_LEAD_DAYS)];
        }
        return [
            'status' => $this->status(),
            'open' => $this->isOpen(),
            'discovered' => $discovered,
            'reported' => $this->dateOf('gemeldet'),
            'fixedOn' => $fixed,
            'days' => $discovered === null ? null : Day::between($discovered, $fixed ?? $today),
            'followUp' => $followUp,
        ];
    }
}
