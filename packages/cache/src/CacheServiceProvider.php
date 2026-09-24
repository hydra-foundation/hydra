<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Wires the cache package into an application.
 */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(CacheConfig::class, function () use ($container) {
            return CacheConfig::fromEnvironment($container->get(Environment::class));
        });

        // One connection per request, opened on first use rather than at boot:
        // a request that never touches the cache should not pay for it, and a
        // Redis outage should not stop the application from serving pages that
        // do not need it.
        $container->singleton(StoreInterface::class, function () use ($container) {
            $config = $container->get(CacheConfig::class);

            // Never silently: see ArrayStore's docblock. Falling back to a
            // per-process store would multiply every limit by the worker count.
            if ($config->driver === CacheConfig::ARRAY) {
                return new ArrayStore;
            }

            return new RedisStore(fn () => RedisConnection::open($config), $config->prefix);
        });
    }
}
