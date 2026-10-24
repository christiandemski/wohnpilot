<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/** One row of the rent history in the lease note. */
final readonly class RentChange
{
    public function __construct(
        public string $validFrom,
        public ?float $coldRent,
        public ?float $utilities,
        public string $reason = '',
        public ?string $received = null,
        public ?string $agreed = null,
    ) {
    }

    /** @param array<string, string> $row */
    public static function fromRow(array $row): ?self
    {
        $validFrom = trim($row['Gültig ab'] ?? '');
        if (!Day::isValid($validFrom)) {
            return null; // a hand-edited row we cannot read stays in the note
        }
        $date = static fn (string $column): ?string => Day::isValid($v = trim($row[$column] ?? '')) ? $v : null;
        return new self(
            validFrom: $validFrom,
            coldRent: Reading::parseNumber($row['Kaltmiete €'] ?? ''),
            utilities: Reading::parseNumber($row['Nebenkosten €'] ?? ''),
            reason: trim($row['Grund'] ?? ''),
            received: $date('Eingang'),
            agreed: $date('Zugestimmt'),
        );
    }
}
