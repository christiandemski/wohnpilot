<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

final readonly class Reading
{
    public const REGULAR = 'regulär';
    public const METER_CHANGE = 'Zählerwechsel';

    public function __construct(
        public string $date,
        public float $value,
        public string $occasion = self::REGULAR,
        public string $photo = '',
        public string $by = '',
    ) {
    }

    public function isMeterChange(): bool
    {
        return mb_strtolower($this->occasion) === mb_strtolower(self::METER_CHANGE);
    }

    /**
     * Reads "23702.0" as the app writes it, and hand-written German forms
     * like "23702,0", "23 702,0" or "23.702,0". Returns null if unreadable.
     */
    public static function parseNumber(string $text): ?float
    {
        $text = str_replace([' ', "\u{202F}", "\u{00A0}", "\u{2009}"], '', trim($text));
        $text = str_replace("\u{2212}", '-', $text);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',')) {
            // German: dots group thousands, the comma is the decimal mark.
            if (!preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/', $text)) {
                return null;
            }
            $text = str_replace(['.', ','], ['', '.'], $text);
        }
        return is_numeric($text) ? (float) $text : null;
    }

    public static function formatNumber(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
