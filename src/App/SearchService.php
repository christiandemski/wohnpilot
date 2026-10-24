<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;

/**
 * Full-text search over the notes of the household and the active home.
 * Every word must appear somewhere (title, fields or text); hits in the
 * title count most. Umlauts match their plain letters ("mangel" finds
 * "Mängel"). The index holds every note, so this needs no extra table.
 */
final class SearchService
{
    public const MAX_RESULTS = 30;
    /** Notes nobody looks for */
    private const SKIPPED = ['haushalt'];
    private const TITLE_FIELDS = ['titel', 'name', 'bezeichnung', 'anbieter'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
    ) {
    }

    /**
     * @return list<array{type: string, id: string, title: string, snippet: string, score: int}>
     */
    public function search(string $query, ?string $type = null): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', self::normalize($query)) ?: [], static fn (string $t): bool => mb_strlen($t) >= 2));
        if ($terms === []) {
            return [];
        }
        $object = $this->household->activeObject()['id'] ?? null;
        $results = [];
        foreach ($this->notes->all() as $stored) {
            $note = NoteDocument::parse($stored->content);
            $fm = $note->frontmatter()->all();
            $typ = (string) ($fm['typ'] ?? '');
            $noteObject = (string) ($fm['objekt'] ?? '');
            if ($typ === '' || in_array($typ, self::SKIPPED, true) || ($type !== null && $typ !== $type) || ($noteObject !== '' && $noteObject !== $object)) {
                continue;
            }
            $title = '';
            foreach (self::TITLE_FIELDS as $key) {
                if (is_string($fm[$key] ?? null) && $fm[$key] !== '') {
                    $title = $fm[$key];
                    break;
                }
            }
            $title = $title !== '' ? $title : (string) ($fm['id'] ?? '');
            $fields = self::flatten(array_diff_key($fm, array_flip(['typ', 'id', 'objekt', 'erstellt_von', 'geaendert_von'])));
            $text = $note->body()->toString();

            $score = 0;
            foreach ($terms as $term) {
                $inTitle = str_contains(self::normalize($title), $term);
                $inFields = str_contains(self::normalize($fields), $term);
                $inText = str_contains(self::normalize($text), $term);
                if (!$inTitle && !$inFields && !$inText) {
                    continue 2;
                }
                $score += ($inTitle ? 10 : 0) + ($inFields ? 3 : 0) + ($inText ? 1 : 0);
            }
            $results[] = ['type' => $typ, 'id' => (string) ($fm['id'] ?? ''), 'title' => $title, 'snippet' => self::snippet($text, $fields, $terms[0]), 'score' => $score];
        }
        usort($results, static fn (array $a, array $b): int => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);
        return array_slice($results, 0, self::MAX_RESULTS);
    }

    /** Lower case, umlauts as plain letters, for comparing only (lengths may change). */
    private static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e']);
    }

    private static function flatten(array $fields): string
    {
        $parts = [];
        array_walk_recursive($fields, static function ($value) use (&$parts): void {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        });
        return implode(' · ', $parts);
    }

    /** Some words around the first hit in the text, else the start of the text, else the hit in the fields. */
    private static function snippet(string $text, string $fields, string $term): string
    {
        $plain = trim((string) preg_replace(['/!\[[^\]]*\]\([^)]*\)/u', '/\[([^\]]*)\]\([^)]*\)/u', '/[|#*_`>-]+/u', '/\s+/u'], ['', '$1', ' ', ' '], $text));
        if ($plain !== '' && self::find(mb_str_split($plain), $term) === null) {
            return mb_substr($plain, 0, 140) . (mb_strlen($plain) > 140 ? ' …' : '');
        }
        foreach ([$plain, $fields] as $source) {
            $chars = mb_str_split($source);
            $at = self::find($chars, $term);
            if ($at !== null) {
                $start = max(0, $at - 50);
                $piece = implode('', array_slice($chars, $start, 140));
                return ($start > 0 ? '… ' : '') . trim($piece) . ($start + 140 < count($chars) ? ' …' : '');
            }
        }
        return mb_substr($plain, 0, 140) . (mb_strlen($plain) > 140 ? ' …' : '');
    }

    /** Position of $term in the characters, comparing normalized letter by letter (ß → ss is two). */
    private static function find(array $chars, string $term): ?int
    {
        $count = count($chars);
        for ($i = 0; $i < $count; $i++) {
            $candidate = '';
            for ($j = $i; $j < $count && mb_strlen($candidate) < mb_strlen($term); $j++) {
                $candidate .= self::normalize($chars[$j]);
            }
            if (str_starts_with($candidate, $term)) {
                return $i;
            }
        }
        return null;
    }
}
