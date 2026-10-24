<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

use Wohnpilot\Note\NoteDocument;

/**
 * A device (`typ: geraet`). Ends of the manufacturer's warranty
 * (`garantie_bis`, or `garantie_monate` from the purchase) and of the
 * seller's statutory liability (Gewährleistung, two years from the
 * purchase) are derived. Devices of the landlord have neither for you.
 */
final readonly class Device
{
    public const LIABILITY_MONTHS = 24;
    /** A warranty ending within this many days shows up as a hint. */
    public const WARRANTY_LEAD_DAYS = 30;
    public const OWNERS = ['mieter', 'vermieter'];

    public function __construct(
        public string $id,
        public string $name,
        public string $owner = 'mieter',
        public ?string $purchased = null,
        public ?int $warrantyMonths = null,
        public ?string $warrantyUntil = null,
        public string $object = '',
    ) {
    }

    public static function fromNote(NoteDocument $note): self
    {
        $fm = $note->frontmatter();
        $date = static fn (string $key): ?string => is_string($v = $fm->get($key)) && Day::isValid($v) ? $v : null;
        $months = $fm->get('garantie_monate');
        return new self(
            id: (string) $fm->get('id', ''),
            name: (string) $fm->get('name', $fm->get('id', '')),
            owner: (string) $fm->get('eigentum', 'mieter'),
            purchased: $date('kaufdatum'),
            warrantyMonths: is_int($months) && $months > 0 ? $months : null,
            warrantyUntil: $date('garantie_bis'),
            object: (string) $fm->get('objekt', ''),
        );
    }

    public function warrantyEnd(): ?string
    {
        if ($this->warrantyUntil !== null) {
            return $this->warrantyUntil;
        }
        return $this->purchased === null || $this->warrantyMonths === null ? null : Day::periodEnd($this->purchased, $this->warrantyMonths);
    }

    public function liabilityEnd(): ?string
    {
        return $this->purchased === null || $this->owner === 'vermieter' ? null : Day::periodEnd($this->purchased, self::LIABILITY_MONTHS);
    }

    /**
     * @return array{warranty: ?array{until: string, daysUntil: int, status: string}, liability: ?array{until: string, daysUntil: int, status: string}}
     *   status ok while it lasts, soon in the last 30 days, due ("abgelaufen") afterwards
     */
    public function evaluate(string $today): array
    {
        $state = static function (?string $until) use ($today): ?array {
            if ($until === null) {
                return null;
            }
            $days = Day::between($today, $until);
            return ['until' => $until, 'daysUntil' => $days, 'status' => $days < 0 ? Urgency::DUE : Urgency::of($days, self::WARRANTY_LEAD_DAYS)];
        };
        return ['warranty' => $state($this->warrantyEnd()), 'liability' => $state($this->liabilityEnd())];
    }
}
