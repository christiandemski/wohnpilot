<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use DateTimeImmutable;
use DateTimeZone;

/** Today's date as ISO string; fixed in tests. */
class Clock
{
    public function __construct(private readonly ?string $fixedToday = null, private readonly string $timezone = 'Europe/Berlin')
    {
    }

    public function today(): string
    {
        return $this->fixedToday ?? (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d');
    }
}
