<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\Frontmatter;

/**
 * Three-way merge for frontmatter fields: the client sends the fields it
 * changed and the values it saw before. A field only conflicts if it was
 * changed elsewhere as well.
 */
final class FieldMerge
{
    /**
     * @param array<string, mixed> $changes field => new value
     * @param array<string, mixed> $original field => value the client started from
     * @return array<string, array{mine: mixed, theirs: mixed}> conflicting fields, empty if merged
     */
    public static function apply(Frontmatter $current, array $changes, array $original): array
    {
        $conflicts = [];
        foreach ($changes as $field => $value) {
            $theirs = $current->get($field);
            $base = $original[$field] ?? null;
            if ($theirs !== $base && $theirs !== $value) {
                $conflicts[$field] = ['mine' => $value, 'theirs' => $theirs];
            }
        }
        if ($conflicts !== []) {
            return $conflicts;
        }
        foreach ($changes as $field => $value) {
            if ($value === null) {
                $current->remove($field);
            } else {
                $current->set($field, $value);
            }
        }
        return [];
    }
}
