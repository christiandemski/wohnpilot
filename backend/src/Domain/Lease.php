<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * The lease of an object (`typ: mietvertrag`) with its rent history.
 * Notice and consent deadlines follow the rules for residential tenancies
 * (§ 573c and § 558b BGB) and are derived, never stored.
 */
final readonly class Lease
{
    public const COLUMNS = ['Gültig ab', 'Kaltmiete €', 'Nebenkosten €', 'Grund', 'Eingang', 'Zugestimmt'];
    public const DEFAULT_NOTICE_MONTHS = 3;

    /** Index and graduated rents change without the tenant's consent. */
    private const NO_CONSENT_TYPES = ['index', 'staffel'];

    /**
     * @param list<RentChange> $history newest first
     * @param array<string, mixed> $deposit the frontmatter map `kaution`
     */
    public function __construct(
        public string $id,
        public string $object,
        public ?string $start = null,
        public string $rentType = 'standard',
        public ?float $coldRent = null,
        public ?float $utilities = null,
        public int $noticeMonths = self::DEFAULT_NOTICE_MONTHS,
        public array $deposit = [],
        public array $history = [],
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $history = [];
        foreach ($note->body()->table()?->rows() ?? [] as $row) {
            $change = RentChange::fromRow($row);
            if ($change !== null) {
                $history[] = $change;
            }
        }
        usort($history, static fn (RentChange $a, RentChange $b): int => strcmp($b->validFrom, $a->validFrom));
        $number = static fn (string $key): ?float => is_numeric($v = $fm->get($key)) ? (float) $v : null;
        $start = $fm->get('beginn');

        return new self(
            id: (string) $fm->get('id', ''),
            object: (string) $fm->get('objekt', ''),
            start: is_string($start) && Day::isValid($start) ? $start : null,
            rentType: (string) $fm->get('mietart', 'standard'),
            coldRent: $number('kaltmiete_eur'),
            utilities: $number('nebenkosten_eur'),
            noticeMonths: is_numeric($fm->get('kuendigungsfrist_monate')) ? (int) $fm->get('kuendigungsfrist_monate') : self::DEFAULT_NOTICE_MONTHS,
            deposit: is_array($fm->get('kaution')) ? $fm->get('kaution') : [],
            history: $history,
        );
    }

    /**
     * Rent in force on $day: the newest history row that has started, else the frontmatter.
     *
     * @return array{cold: ?float, utilities: ?float, validFrom: ?string}
     */
    public function rentOn(string $day): array
    {
        foreach ($this->history as $change) {
            if ($change->validFrom <= $day) {
                return [
                    'cold' => $change->coldRent ?? $this->coldRent,
                    'utilities' => $change->utilities ?? $this->utilities,
                    'validFrom' => $change->validFrom,
                ];
            }
        }
        return ['cold' => $this->coldRent, 'utilities' => $this->utilities, 'validFrom' => $this->start];
    }

    /**
     * If you give notice now: it must arrive by the third working day of a month,
     * then the lease ends with the last day of the month $noticeMonths − 1 later
     * (3 months: by 03.11. → end of January).
     *
     * @return array{noticeBy: string, endsOn: string, daysUntil: int}
     */
    public function tenantNotice(string $today): array
    {
        $month = Day::month($today);
        $noticeBy = WorkingDay::nth($month, 3);
        if ($today > $noticeBy) {
            $month = Day::addMonths($month, 1);
            $noticeBy = WorkingDay::nth($month, 3);
        }
        return [
            'noticeBy' => $noticeBy,
            'endsOn' => Day::lastOfMonth(Day::addMonths($month, max(1, $this->noticeMonths) - 1)),
            'daysUntil' => Day::between($today, $noticeBy),
        ];
    }

    /**
     * Open rent increases that need your consent: until the end of the second
     * month after the letter arrived (arrived 15.10. → consent by 31.12.).
     *
     * @return list<array{validFrom: string, received: string, consentBy: string, daysUntil: int, status: string, coldRent: ?float}>
     */
    public function consentDeadlines(string $today): array
    {
        if (in_array($this->rentType, self::NO_CONSENT_TYPES, true)) {
            return [];
        }
        $deadlines = [];
        foreach ($this->history as $change) {
            if ($change->received === null || $change->agreed !== null) {
                continue;
            }
            $consentBy = Day::lastOfMonth(Day::addMonths(Day::month($change->received), 2));
            $days = Day::between($today, $consentBy);
            $deadlines[] = [
                'validFrom' => $change->validFrom,
                'received' => $change->received,
                'consentBy' => $consentBy,
                'daysUntil' => $days,
                'status' => Urgency::of($days, Urgency::CONTRACT_LEAD_DAYS),
                'coldRent' => $change->coldRent,
            ];
        }
        return $deadlines;
    }
}
