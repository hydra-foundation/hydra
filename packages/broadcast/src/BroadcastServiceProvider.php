<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Drivers\NullBroadcaster;
use Hydra\Broadcast\Drivers\PhpRedisChannel;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\RedisConnection;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the broadcast package into an application. Nothing is configured or
 * connected until something publishes.
 */
final class BroadcastServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(BroadcastConfig::class, function () use ($container) {
            return BroadcastConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(BroadcasterInterface::class, function () use ($container) {
            $config = $container->get(BroadcastConfig::class);

            return match ($config->driver) {
                BroadcastConfig::REDIS => new RedisBroadcaster(
                    // The cache's settings and its hardened opener, whatever
                    // CACHE_STORE says: open() reads only where the server is.
                    fn () => new PhpRedisChannel(RedisConnection::open(
                        CacheConfig::fromEnvironment($container->get(Environment::class)),
                    )),
                    $config->channelPrefix,
                    $this->logger($container),
                    $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
                ),
                BroadcastConfig::LOG => new LogBroadcaster($this->logger($container)),
                default => new NullBroadcaster,
            };
        });
    }

    private function logger(ContainerInterface $container): LoggerInterface
    {
        return $container->bound(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger;
    }
}
