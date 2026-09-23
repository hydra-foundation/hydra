<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds an empty Schedule for the application to fill from its own provider's
 * boot(), and the Runner that schedule:run drives.
 */
final class SchedulerServiceProvider extends ServiceProvider
{
    /** @param string $lockPath where task locks live unless SCHEDULE_LOCK_DIR says otherwise */
    public function __construct(private readonly string $lockPath) {}

    public function register(ContainerInterface $container): void
    {
        $container->singleton(SchedulerConfig::class, function () use ($container) {
            return SchedulerConfig::fromEnvironment($container->get(Environment::class), $this->lockPath);
        });

        $container->singleton(Schedule::class, function () use ($container) {
            return new Schedule($container->get(SchedulerConfig::class)->timezone);
        });

        $container->singleton(Runner::class, function () use ($container) {
            return new Runner(
                $container->get(Schedule::class),
                $container,
                $container->get(ClockInterface::class),
                new LockDirectory($container->get(SchedulerConfig::class)->lockPath),
                $container->get(LoggerInterface::class),
            );
        });
    }
}
