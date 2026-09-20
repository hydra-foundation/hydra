<?php

declare(strict_types=1);

namespace Hydra\Admin;

use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The stretch of time a dashboard is asking about.
 *
 * Today and yesterday are calendar days because they mean nothing else; the
 * longer ones roll backwards from now, so that a month is always thirty days
 * of data rather than however much of the current one has elapsed.
 *
 * A calendar day needs a midnight and a midnight needs a zone, so the reader's
 * is asked for rather than assumed. The process runs in UTC, and a dashboard
 * that took its midnight from there would drop the evening's rows the moment
 * UTC rolled over — hours before the day ended for the person looking at it.
 */
enum Period: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case All = 'all';

    /** What a dashboard opens on: the day in front of you. */
    public const DEFAULT = self::Today;

    /** The period a URL names, or the default when it names nothing we serve. */
    public static function fromKey(?string $key): self
    {
        return ($key === null ? null : self::tryFrom($key)) ?? self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Week => 'Last 7 days',
            self::Month => 'Last 30 days',
            self::Year => 'Last 12 months',
            self::All => 'All time',
        };
    }

    public function window(ClockInterface $clock, ?DateTimeZone $zone = null): Window
    {
        $now = $clock->now();

        if ($zone !== null) {
            $now = $now->setTimezone($zone);
        }

        $midnight = $now->setTime(0, 0);

        return match ($this) {
            self::Today => new Window($this, $midnight),
            self::Yesterday => new Window($this, $midnight->modify('-1 day'), $midnight),
            self::Week => new Window($this, $now->modify('-7 days')),
            self::Month => new Window($this, $now->modify('-30 days')),
            self::Year => new Window($this, $now->modify('-365 days')),
            self::All => new Window($this),
        };
    }
}
