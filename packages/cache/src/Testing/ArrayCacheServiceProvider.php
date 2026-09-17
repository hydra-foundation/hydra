<?php

declare(strict_types=1);

namespace Hydra\Cache\Testing;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Test counterpart to {@see \Hydra\Cache\CacheServiceProvider}: binds the
 * in-memory store rather than connecting to Redis.
 *
 * A harness needs a real store because the rate limiter counts into one on
 * every request, and a per-process counter is exactly right where the process
 * is the whole application. Register it ahead of the real provider; a container
 * built per test then starts with an empty budget.
 */
final class ArrayCacheServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(StoreInterface::class, new ArrayStore);
    }
}
