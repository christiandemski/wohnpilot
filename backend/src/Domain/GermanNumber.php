<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * German number format for texts the backend builds ("Hochrechnung ≈ 3 160 kWh"):
 * decimal comma, narrow no-break space (U+202F) between thousands.
 */
final class GermanNumber
{
    public const THOUSANDS = "\u{202F}";
    public const MINUS = "\u{2212}";

    public static function format(float $value, int $decimals = 0): string
    {
        $text = number_format(abs($value), $decimals, ',', self::THOUSANDS);
        $isZero = (float) number_format(abs($value), $decimals, '.', '') === 0.0;
        return ($value < 0 && !$isZero ? self::MINUS : '') . $text;
    }
}
