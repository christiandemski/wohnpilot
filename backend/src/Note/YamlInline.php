<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

use Symfony\Component\Yaml\Yaml;

/**
 * Formats values as single-line YAML in the style of the notes:
 * ISO dates unquoted, lists as [a, b], maps as { k: v }. Text stays
 * unquoted where YAML reads it back unchanged (`titel: Strom melden`),
 * else it goes in double quotes (as in `zaehlernummer: "[Nummer]"`).
 */
final class YamlInline
{
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public static function format(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(self::format(...), $value)) . ']';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::formatKey((string) $key) . ': ' . self::format($item);
            }
            return $parts === [] ? '{}' : '{ ' . implode(', ', $parts) . ' }';
        }
        if (is_string($value) && preg_match(self::ISO_DATE, $value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::formatFloat($value);
        }
        if (is_string($value)) {
            return self::formatString($value);
        }
        return Yaml::dump($value);
    }

    private static function formatKey(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) ? $key : Yaml::dump($key);
    }

    private static function formatString(string $value): string
    {
        if (self::isPlain($value)) {
            return $value;
        }
        // A JSON string is a valid YAML double-quoted scalar.
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Plain (unquoted) is safe for single-line text without flow characters
     * (the value may sit inside [a, b] or { k: v }), comments or leading
     * indicators, and only if it does not read back as something else
     * (true, 12, null).
     */
    private static function isPlain(string $value): bool
    {
        if ($value === '' || $value !== trim($value) || preg_match('/[\x00-\x1f,\[\]{}"]|: |:$| #/u', $value)) {
            return false;
        }
        if (strpbrk($value[0], "-?:#&*!|>'%@`") !== false) {
            return false;
        }
        // Booleans in YAML 1.1, which other tools may still use.
        if (in_array(strtolower($value), ['yes', 'no', 'on', 'off', 'y', 'n'], true)) {
            return false;
        }
        try {
            return Yaml::parse('v: ' . $value) === ['v' => $value];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function formatFloat(float $value): string
    {
        if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return number_format($value, 1, '.', '');
        }
        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        return str_contains($text, '.') ? $text : $text . '.0';
    }
}
