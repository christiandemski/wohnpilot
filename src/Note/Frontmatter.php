<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

use DateTimeInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML frontmatter kept as raw lines.
 *
 * Reading parses the YAML; writing only touches the block of the key that
 * changed. Unknown keys, comments, order and formatting stay as they are.
 */
final class Frontmatter
{
    /** @var list<string> */
    private array $lines;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @param list<string> $lines raw lines between the delimiters, with terminators */
    public function __construct(array $lines, private readonly string $eol = "\n")
    {
        $this->lines = $lines;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->data === null) {
            $parsed = Yaml::parse(implode('', $this->lines), Yaml::PARSE_DATETIME);
            $this->data = is_array($parsed) ? self::normalize($parsed) : [];
        }
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** Sets a top-level key. An unchanged value leaves the text untouched. */
    /**
     * A single-line value as written, for things YAML would turn into a
     * number: "+491701234567" stays with its plus. Quoted values come back
     * as YAML reads them; null if the key is missing or not one line.
     */
    public function raw(string $key): ?string
    {
        $block = $this->findBlock($key);
        if ($block === null || $block[1] !== 1 || is_array($this->get($key))) {
            return null;
        }
        $line = Lines::content($this->lines[$block[0]]);
        $text = trim((string) preg_replace('/^' . preg_quote($key, '/') . '\s*:/', '', $line));
        $text = trim((string) preg_replace('/\s+#.*$/', '', $text));
        if ($text === '' || str_starts_with($text, '"') || str_starts_with($text, "'")) {
            $value = $this->get($key);
            return is_scalar($value) ? (string) $value : null;
        }
        return $text;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->has($key) && $this->get($key) === $value) {
            return;
        }
        $line = $key . ': ' . YamlInline::format($value);
        $block = $this->findBlock($key);
        if ($block === null) {
            $this->appendLine($line);
        } else {
            [$start, $length] = $block;
            $terminator = Lines::terminator($this->lines[$start + $length - 1]);
            array_splice($this->lines, $start, $length, [$line . ($terminator === '' ? '' : $this->eol)]);
        }
        $this->data = null;
    }

    /**
     * Sets one key inside a map ($value null removes it). In a block map
     *
     *   einstellungen:
     *     ablesetag: 1
     *
     * only that one line changes or is added, so the note keeps its layout.
     * Inline or missing maps are written as a whole.
     */
    public function setIn(string $key, string $child, mixed $value): void
    {
        $current = $this->get($key);
        $map = is_array($current) && !array_is_list($current) ? $current : [];
        if (array_key_exists($child, $map) ? $map[$child] === $value : $value === null) {
            return;
        }
        $block = $this->findBlock($key);
        $indent = $block === null || $block[1] < 2 || trim(Lines::content($this->lines[$block[0]])) !== "$key:"
            ? null
            : (preg_match('/^(\s+)\S/', Lines::content($this->lines[$block[0] + 1]), $m) ? $m[1] : null);
        if ($indent === null || ($current !== null && !is_array($current))) {
            if ($value === null) {
                unset($map[$child]);
            } else {
                $map[$child] = $value;
            }
            $map === [] ? $this->remove($key) : $this->set($key, $map);
            return;
        }

        [$start, $length] = $block;
        $childLine = null;
        for ($i = $start + 1; $i < $start + $length; $i++) {
            if (preg_match('/^' . preg_quote($indent . $child, '/') . '\s*:(\s|$)/', Lines::content($this->lines[$i]))) {
                $childLine = $i;
                break;
            }
        }
        $line = $indent . $child . ': ' . YamlInline::format($value) . $this->eol;
        if ($childLine !== null) {
            // Only single-line values are replaced in place; a nested child is rewritten as a whole.
            $next = $childLine + 1 < $start + $length ? Lines::content($this->lines[$childLine + 1]) : '';
            if (str_starts_with($next, $indent . ' ') || str_starts_with($next, $indent . "\t")) {
                $map[$child] = $value;
                $this->set($key, array_filter($map, static fn ($v) => $v !== null));
                return;
            }
            array_splice($this->lines, $childLine, 1, $value === null ? [] : [$line]);
            if ($value === null && $length === 2) {
                // That was the only key: the empty map goes too.
                array_splice($this->lines, $start, 1);
            }
        } elseif ($value !== null) {
            $last = $start + $length - 1;
            if (Lines::terminator($this->lines[$last]) === '') {
                $this->lines[$last] .= $this->eol;
            }
            array_splice($this->lines, $last + 1, 0, [$line]);
        }
        $this->data = null;
    }

    public function remove(string $key): void
    {
        $block = $this->findBlock($key);
        if ($block !== null) {
            array_splice($this->lines, $block[0], $block[1]);
            $this->data = null;
        }
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function toString(): string
    {
        return implode('', $this->lines);
    }

    /**
     * Start index and length of the lines belonging to a top-level key:
     * the key line plus following indented, list or blank lines.
     *
     * @return array{int, int}|null
     */
    private function findBlock(string $key): ?array
    {
        $pattern = '/^' . preg_quote($key, '/') . '\s*:(\s|$)/';
        foreach ($this->lines as $index => $line) {
            if (!preg_match($pattern, Lines::content($line))) {
                continue;
            }
            $end = $index + 1;
            while ($end < count($this->lines) && self::continuesBlock(Lines::content($this->lines[$end]))) {
                $end++;
            }
            // Trailing blank lines belong to the layout, not to the key.
            while ($end - 1 > $index && trim(Lines::content($this->lines[$end - 1])) === '') {
                $end--;
            }
            return [$index, $end - $index];
        }
        return null;
    }

    private static function continuesBlock(string $content): bool
    {
        return $content === ''
            || trim($content) === ''
            || $content[0] === ' '
            || $content[0] === "\t"
            || str_starts_with($content, '- ')
            || $content === '-';
    }

    private function appendLine(string $line): void
    {
        $count = count($this->lines);
        if ($count > 0 && Lines::terminator($this->lines[$count - 1]) === '') {
            $this->lines[$count - 1] .= $this->eol;
        }
        $this->lines[] = $line . $this->eol;
    }

    /**
     * Dates stay ISO strings: a date-only value comes back as "2026-10-24".
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($item instanceof DateTimeInterface) {
                $value[$key] = $item->format('H:i:s') === '00:00:00'
                    ? $item->format('Y-m-d')
                    : $item->format(DateTimeInterface::ATOM);
            } elseif (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }
        return $value;
    }
}
