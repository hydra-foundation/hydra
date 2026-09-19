<?php

declare(strict_types=1);

namespace Hydra\Core\Clock;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Psr\Clock\ClockInterface;

final class ClockServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(ClockInterface::class, fn () => new SystemClock);
    }
}
