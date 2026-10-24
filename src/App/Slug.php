<?php

declare(strict_types=1);

namespace Wohnpilot\App;

final class Slug
{
    /** "EFH Musterstraße 12" → "efh-musterstrasse-12" */
    public static function from(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1;
    }

    /** Appends -2, -3, … until $isTaken says no. */
    public static function unique(string $slug, callable $isTaken): string
    {
        $candidate = $slug;
        for ($i = 2; $isTaken($candidate); $i++) {
            $candidate = $slug . '-' . $i;
        }
        return $candidate;
    }
}
