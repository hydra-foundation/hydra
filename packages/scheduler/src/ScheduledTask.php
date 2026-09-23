<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;

/** One entry in a {@see Schedule}: which class, how often, and how long a run may take before it is reported. */
final class ScheduledTask
{
    public const DEFAULT_WARN_MINUTES = 60;

    private ?CronExpression $cron = null;

    private int $warnMinutes = self::DEFAULT_WARN_MINUTES;

    private ?int $budgetMinutes = null;

    /** @param class-string $class */
    public function __construct(
        public readonly string $class,
        public readonly bool $batched,
    ) {}

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    /** @param string $time 24-hour "HH:MM" in the schedule's zone */
    public function dailyAt(string $time): self
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new InvalidArgumentException("dailyAt() takes a 24-hour \"HH:MM\"; \"{$time}\" is not one.");
        }

        return $this->cron((int) $m[2] . ' ' . (int) $m[1] . ' * * *');
    }

    /** Sunday at midnight, as cron's own @weekly. */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function cron(string $expression): self
    {
        $this->cron = CronExpression::parse($expression);

        return $this;
    }

    /**
     * How long a run may hold its lock before each tick that finds it still
     * held logs a warning. The lock is never taken over: a run that died
     * released it with its process, and one still holding it is alive, so a
     * second copy beside it would only make two of whatever went wrong.
     */
    public function warnAfter(int $minutes): self
    {
        if ($minutes < 1) {
            throw new InvalidArgumentException('warnAfter() takes at least one minute.');
        }

        $this->warnMinutes = $minutes;

        return $this;
    }

    /** How long one run may keep calling batch() before it stops and waits for the next. */
    public function for(int $minutes): self
    {
        if (!$this->batched) {
            throw new LogicException("{$this->class} is not a batch; for() is for entries declared with drain().");
        }

        if ($minutes < 1) {
            throw new InvalidArgumentException('for() takes at least one minute.');
        }

        $this->budgetMinutes = $minutes;

        return $this;
    }

    public function isDue(DateTimeInterface $at): bool
    {
        return $this->expression()->isDue($at);
    }

    public function expression(): CronExpression
    {
        return $this->cron ?? throw new LogicException(
            "{$this->class} is scheduled with no frequency; give it everyMinute(), hourly(), dailyAt(), weekly() or cron().",
        );
    }

    public function warnMinutes(): int
    {
        return $this->warnMinutes;
    }

    /** Null for a batch that runs until it reports nothing left. */
    public function budgetMinutes(): ?int
    {
        return $this->budgetMinutes;
    }
}
