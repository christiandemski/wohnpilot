<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Domain\Season;

/**
 * Checks form input field by field and collects frontmatter values.
 *
 * Creating: required fields must be there, empty values are left out.
 * Updating: only fields present in the input are checked; an empty value
 * becomes null, which removes the field from the note.
 */
final class Input
{
    /** @var array<string, mixed> */
    private array $fields = [];
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $input */
    public function __construct(private readonly array $input, private readonly bool $creating)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function value(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }

    public function string(string $key, bool $required = false): self
    {
        return $this->check($key, $required, static fn (mixed $v): array => is_scalar($v) ? [trim((string) $v), null] : [null, 'text']);
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, bool $required = false, ?string $default = null): self
    {
        if ($this->creating && $default !== null && $this->isEmpty($this->input[$key] ?? null)) {
            $this->fields[$key] = $default;
            return $this;
        }
        return $this->check($key, $required, static fn (mixed $v): array => in_array($v, $allowed, true) ? [$v, null] : [null, 'unknown']);
    }

    public function date(string $key, bool $required = false): self
    {
        return $this->check($key, $required, static fn (mixed $v): array => is_string($v) && Day::isValid($v) ? [$v, null] : [null, 'date']);
    }

    public function int(string $key, int $min, int $max, bool $required = false): self
    {
        return $this->check($key, $required, static function (mixed $v) use ($min, $max): array {
            if (is_string($v) && preg_match('/^-?\d+$/', trim($v))) {
                $v = (int) $v;
            }
            return is_int($v) && $v >= $min && $v <= $max ? [$v, null] : [null, 'range'];
        });
    }

    /** A non-negative amount; "1.234,56" from a German keyboard is accepted too. */
    public function number(string $key, bool $required = false): self
    {
        return $this->check($key, $required, static function (mixed $v): array {
            $n = is_int($v) || is_float($v) ? $v : (is_string($v) ? Reading::parseNumber($v) : null);
            if ($n === null || $n < 0) {
                return [null, 'number'];
            }
            return [is_float($n) && floor($n) === $n && abs($n) < 1e9 ? (int) $n : $n, null];
        });
    }

    /** A list of note ids, e.g. `bezieht_sich_auf`. */
    public function slugList(string $key): self
    {
        return $this->check($key, false, static function (mixed $v): array {
            $list = is_array($v) ? $v : [$v];
            $list = array_values(array_unique(array_filter(array_map(static fn ($s) => is_string($s) ? trim($s) : '', $list), 'strlen')));
            foreach ($list as $slug) {
                if (!Slug::isValid($slug)) {
                    return [null, 'id'];
                }
            }
            return [$list === [] ? null : $list, null];
        });
    }

    public function bool(string $key): self
    {
        return $this->check($key, false, static fn (mixed $v): array => is_bool($v) ? [$v, null] : [null, 'unknown']);
    }

    /** Time of day "08:00" (also "8:00", stored as "08:00"). */
    public function time(string $key): self
    {
        return $this->check($key, false, static function (mixed $v): array {
            if (!is_string($v) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($v), $m)) {
                return [null, 'time'];
            }
            return [sprintf('%02d:%s', (int) $m[1], $m[2]), null];
        });
    }

    /** An http(s) address. */
    public function url(string $key): self
    {
        return $this->check($key, false, static function (mixed $v): array {
            $url = is_string($v) ? trim($v) : '';
            $ok = filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
            return $ok && strlen($url) <= 500 ? [$url, null] : [null, 'url'];
        });
    }

    /** Months a task may be done: `{ von: 10, bis: 2 }`. */
    public function season(string $key): self
    {
        return $this->check($key, false, static function (mixed $v): array {
            $season = Season::fromValue(is_array($v) ? array_map(static fn ($m) => is_numeric($m) ? (int) $m : $m, $v) : null);
            return $season === null ? [null, 'season'] : [$season->toArray(), null];
        });
    }

    /** @param list<string> $allowed several of them, e.g. heating types */
    public function enumList(string $key, array $allowed): self
    {
        return $this->check($key, false, static function (mixed $v) use ($allowed): array {
            $list = array_values(array_unique(is_array($v) ? $v : [$v]));
            foreach ($list as $item) {
                if (!in_array($item, $allowed, true)) {
                    return [null, 'unknown'];
                }
            }
            return [$list === [] ? null : $list, null];
        });
    }

    public function error(string $key, string $problem): self
    {
        $this->errors[$key] = $problem;
        return $this;
    }

    /** Sets a value that does not come from the input as such (e.g. a map built from several fields). */
    public function set(string $key, mixed $value): self
    {
        $this->fields[$key] = $value;
        return $this;
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        if ($this->errors !== []) {
            throw AppException::validation($this->errors);
        }
        return $this->creating ? array_filter($this->fields, static fn ($v) => $v !== null) : $this->fields;
    }

    /** @param callable(mixed): array{mixed, ?string} $parse */
    private function check(string $key, bool $required, callable $parse): self
    {
        if (!$this->has($key)) {
            if ($this->creating && $required) {
                $this->errors[$key] = 'required';
            }
            return $this;
        }
        $raw = $this->input[$key];
        if ($this->isEmpty($raw)) {
            if ($required) {
                $this->errors[$key] = 'required';
            } else {
                $this->fields[$key] = null;
            }
            return $this;
        }
        [$value, $problem] = $parse($raw);
        if ($problem !== null) {
            $this->errors[$key] = $problem;
        } elseif ($required && $this->isEmpty($value)) {
            $this->errors[$key] = 'required';
        } else {
            $this->fields[$key] = $this->isEmpty($value) ? null : $value;
        }
        return $this;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
