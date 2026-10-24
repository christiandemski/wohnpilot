<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A recurring task (`typ: wartung`) with an interval instead of a date.
 *
 * Next due date = last done + interval. Never done: due on `ab`, else now.
 * With a season the task is due within a window of months: it may be done
 * from the start of the window that holds (or follows) that date and is due
 * by its end – once per season, e.g. hedge cutting October to February.
 *
 * How it gets done (`ausfuehrung`): yourself (default), by a service that
 * comes by appointment, or automatically by radio (e.g. smoke detectors read
 * remotely by the meter service) – then it is never due.
 */
final readonly class MaintenanceTask
{
    public const DEFAULT_LEAD_DAYS = 14;
    public const HISTORY_COLUMNS = ['Datum', 'Erledigt von', 'Notiz'];
    public const RESPONSIBLE = ['mieter', 'vermieter', 'dienstleister'];
    public const SELF = 'selbst';
    public const APPOINTMENT = 'termin';
    public const RADIO = 'funk';
    public const EXECUTIONS = [self::SELF, self::APPOINTMENT, self::RADIO];

    /**
     * @param list<string> $done dates the task was done, newest first
     * @param list<string> $refersTo ids of devices, meters, …
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?int $intervalMonths,
        public ?int $intervalWeeks = null,
        public ?Season $season = null,
        public ?string $start = null,
        public int $leadDays = self::DEFAULT_LEAD_DAYS,
        public string $responsible = 'mieter',
        public array $done = [],
        public array $refersTo = [],
        public string $object = '',
        public string $execution = self::SELF,
    ) {
    }

    /** Done by radio: nothing to do, never due. */
    public function isAutomatic(): bool
    {
        return $this->execution === self::RADIO;
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $positive = static fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        $done = [];
        foreach ($note->body()->table()?->rows() ?? [] as $row) {
            $date = trim($row['Datum'] ?? '');
            if (Day::isValid($date)) {
                $done[] = $date;
            }
        }
        rsort($done);
        $start = $fm->get('ab');
        $refersTo = $fm->get('bezieht_sich_auf', []);
        return new self(
            id: (string) $fm->get('id', ''),
            title: (string) $fm->get('titel', $fm->get('id', '')),
            intervalMonths: $positive($fm->get('intervall_monate')),
            intervalWeeks: $positive($fm->get('intervall_wochen')),
            season: Season::fromValue($fm->get('saison')),
            start: is_string($start) && Day::isValid($start) ? $start : null,
            leadDays: is_numeric($fm->get('vorlauf_tage')) ? max(0, (int) $fm->get('vorlauf_tage')) : self::DEFAULT_LEAD_DAYS,
            responsible: (string) $fm->get('zustaendig', 'mieter'),
            done: $done,
            refersTo: is_array($refersTo) ? array_values(array_map('strval', $refersTo)) : [(string) $refersTo],
            object: (string) $fm->get('objekt', ''),
            execution: in_array($fm->get('ausfuehrung'), self::EXECUTIONS, true) ? (string) $fm->get('ausfuehrung') : self::SELF,
        );
    }

    public function lastDone(): ?string
    {
        return $this->done[0] ?? null;
    }

    /** The date the interval points to, before any season. */
    private function nextByInterval(string $today): string
    {
        $last = $this->lastDone();
        if ($last === null) {
            return $this->start ?? $today;
        }
        if ($this->intervalWeeks !== null && $this->intervalMonths === null) {
            return Day::addDays($last, 7 * $this->intervalWeeks);
        }
        return Day::addMonthsToDate($last, $this->intervalMonths ?? 12);
    }

    /**
     * @return array{dueDate: string, from: ?string, daysUntil: int, status: string, lastDone: ?string, inSeason: ?bool, missedSeason: bool, automatic: bool}
     *   from: first day it may be done (season start), inSeason: whether today lies in a window,
     *   missedSeason: the season it was due in passed without it, automatic: done by radio (status always ok)
     */
    public function evaluate(string $today): array
    {
        $next = $this->nextByInterval($today);
        $from = null;
        $inSeason = null;
        $missedSeason = false;
        if ($this->season !== null) {
            [$from, $next] = $this->season->windowFrom($next);
            // A season that is over cannot be caught up: on to the current or next one.
            if ($next < $today) {
                [$from, $next] = $this->season->windowFrom($today);
                $missedSeason = true;
            }
            $inSeason = $this->season->windowContaining($today) !== null;
        }
        $days = Day::between($today, $next);
        return [
            'dueDate' => $next,
            'from' => $from,
            'daysUntil' => $days,
            'status' => $this->isAutomatic() ? Urgency::OK : Urgency::of($days, $this->leadDays),
            'lastDone' => $this->lastDone(),
            'inSeason' => $inSeason,
            'missedSeason' => $missedSeason,
            'automatic' => $this->isAutomatic(),
        ];
    }
}
