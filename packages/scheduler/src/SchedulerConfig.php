<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeZone;
use Exception;
use Hydra\Core\Environment;
use InvalidArgumentException;

final readonly class SchedulerConfig
{
    public function __construct(
        public DateTimeZone $timezone,
        public string $lockPath,
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

        return new self($timezone, $env->string('SCHEDULE_LOCK_DIR') ?: $lockPath);
    }
}
