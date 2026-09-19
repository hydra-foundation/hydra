<?php

declare(strict_types=1);

namespace Hydra\Core\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** The wall clock, in the given zone or PHP's default one. */
final class SystemClock implements ClockInterface
{
    public function __construct(private readonly ?DateTimeZone $timezone = null) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
