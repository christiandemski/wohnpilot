<?php

declare(strict_types=1);

namespace Wohnpilot\Domain;

/**
 * Months in which a task may be done, e.g. hedge cutting from October to
 * February (`saison: { von: 10, bis: 2 }`). The window may wrap the year end.
 */
final readonly class Season
{
    public function __construct(public int $from, public int $to)
    {
    }

    /** From a `saison` map; null if it is missing or not two months 1–12. */
    public static function fromValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }
        $from = $value['von'] ?? null;
        $to = $value['bis'] ?? null;
        if (!is_int($from) || !is_int($to) || $from < 1 || $from > 12 || $to < 1 || $to > 12) {
            return null;
        }
        return new self($from, $to);
    }

    /** @return array{string, string}|null first and last day of the window that contains $day */
    public function windowContaining(string $day): ?array
    {
        $year = (int) substr($day, 0, 4);
        $month = (int) substr($day, 5, 2);
        if ($this->from <= $this->to) {
            return $month >= $this->from && $month <= $this->to ? $this->window($year) : null;
        }
        if ($month >= $this->from) {
            return $this->window($year);
        }
        return $month <= $this->to ? $this->window($year - 1) : null;
    }

    /** @return array{string, string} the window that contains $day, or else the next one after it */
    public function windowFrom(string $day): array
    {
        $containing = $this->windowContaining($day);
        if ($containing !== null) {
            return $containing;
        }
        $year = (int) substr($day, 0, 4);
        $window = $this->window($year);
        return $window[0] > $day ? $window : $this->window($year + 1);
    }

    /** @return array{string, string} the window that starts in $year */
    private function window(int $year): array
    {
        $start = sprintf('%04d-%02d-01', $year, $this->from);
        $endYear = $this->from <= $this->to ? $year : $year + 1;
        return [$start, Day::lastOfMonth(sprintf('%04d-%02d', $endYear, $this->to))];
    }

    /** @return array{von: int, bis: int} */
    public function toArray(): array
    {
        return ['von' => $this->from, 'bis' => $this->to];
    }
}
