<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

final readonly class Tariff
{
    public function __construct(
        public ?float $energyPriceCt,
        public ?float $basePriceEurMonth,
        public ?float $instalmentEur,
    ) {
    }

    /** @param array<string, mixed> $tarif the frontmatter map `tarif` */
    public static function fromArray(array $tarif): self
    {
        $number = static fn (string $key): ?float => is_numeric($tarif[$key] ?? null) ? (float) $tarif[$key] : null;
        return new self($number('arbeitspreis_ct'), $number('grundpreis_eur_monat'), $number('abschlag_eur'));
    }
}
