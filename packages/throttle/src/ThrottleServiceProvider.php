<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\ClientIpResolver;

/**
 * Wires the throttle package into an application. The store and the client
 * resolver are expected to be bound already: the cache package binds the first,
 * and the http package the second.
 */
final class ThrottleServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(ThrottleConfig::class, function () use ($container) {
            return ThrottleConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(RateLimiter::class, function () use ($container) {
            return new RateLimiter(
                $container->get(StoreInterface::class),
                $container->get(ClientIpResolver::class),
            );
        });

        $container->singleton(RateLimitMiddleware::class, function () use ($container) {
            $config = $container->get(ThrottleConfig::class);

            return new RateLimitMiddleware(
                $container->get(RateLimiter::class),
                $config->policy(),
                $config->enabled,
            );
        });
    }
}
