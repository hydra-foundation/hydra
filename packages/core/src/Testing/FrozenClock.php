<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * A clock that stands still until a test moves it, so expiry and "3 minutes
 * ago" are asserted by advancing time rather than sleeping through it.
 *
 * Mutable on purpose: bound once in the container, every service holding it
 * sees the same moment move.
 */
final class FrozenClock implements ClockInterface
{
    /** Any fixed instant; a test that cares about the date says so. */
    public const DEFAULT = '2026-01-01T12:00:00+00:00';

    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = self::DEFAULT)
    {
        $this->set($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable|string $now): void
    {
        $this->now = is_string($now) ? new DateTimeImmutable($now) : $now;
    }

    /** By an interval, or a relative format such as '+90 seconds' or '-1 day'. */
    public function advance(DateInterval|string $by): void
    {
        if ($by instanceof DateInterval) {
            $this->now = $this->now->add($by);

            return;
        }

        // Checked first because modify() warns on 8.2 and throws on 8.3+.
        $moved = strtotime($by) === false ? false : $this->now->modify($by);

        if ($moved === false) {
            throw new InvalidArgumentException("Cannot advance the clock by \"{$by}\".");
        }

        $this->now = $moved;
    }
}
