<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeZone;
use Exception;
use Hydra\Core\Environment;
use InvalidArgumentException;

final readonly class SchedulerConfig
{
    public const DEFAULT_KEEP_DAYS = 7;

    /** @param int $keepDays how long a recorded run is kept before it is pruned */
    public function __construct(
        public DateTimeZone $timezone,
        public string $lockPath,
        public int $keepDays = self::DEFAULT_KEEP_DAYS,
    ) {}

    /**
     * SCHEDULE_TIMEZONE, else the application's APP_TIMEZONE, else UTC: a
     * schedule reads "04:00" the way whoever wrote it meant it.
     */
    public static function fromEnvironment(Environment $env, string $lockPath): self
    {
        $zone = $env->string('SCHEDULE_TIMEZONE') ?: $env->string('APP_TIMEZONE') ?: 'UTC';

        try {
            $timezone = new DateTimeZone($zone);
        } catch (Exception) {
            throw new InvalidArgumentException("The schedule's timezone \"{$zone}\" is not one PHP knows; set SCHEDULE_TIMEZONE or APP_TIMEZONE to an identifier such as Europe/London.");
        }

        $keepDays = $env->int('SCHEDULE_KEEP_DAYS', self::DEFAULT_KEEP_DAYS);

        if ($keepDays < 1) {
            throw new InvalidArgumentException("SCHEDULE_KEEP_DAYS must be at least 1; it is {$keepDays}.");
        }

        return new self($timezone, $env->string('SCHEDULE_LOCK_DIR') ?: $lockPath, $keepDays);
    }
}
