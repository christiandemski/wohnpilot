<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

use Symfony\Component\Yaml\Yaml;

/**
 * Formats values as single-line YAML in the style of the notes:
 * ISO dates unquoted, lists as [a, b], maps as { k: v }.
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
        return Yaml::dump($value);
    }

    private static function formatKey(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) ? $key : Yaml::dump($key);
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
