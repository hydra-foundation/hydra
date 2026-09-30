<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use Hydra\Broadcast\Console\SseServeCommand;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Drivers\NullBroadcaster;
use Hydra\Broadcast\Drivers\PhpRedisChannel;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\HubFactory;
use Hydra\Broadcast\Hub\HubHealthCheck;
use Hydra\Broadcast\Hub\HubStatus;
use Hydra\Broadcast\Hub\RedisHubStatus;
use Hydra\Broadcast\Hub\RedisSubscriber;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\RedisConnection;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Security\Signer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the broadcast package into an application: the publisher, listen
 * tokens and who may listen, and the hub. Nothing is configured or connected
 * until something asks for it.
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
                    fn () => new PhpRedisChannel(RedisConnection::open($this->redis($container))),
                    $config->channelPrefix,
                    $this->logger($container),
                    $this->clock($container),
                ),
                BroadcastConfig::LOG => new LogBroadcaster($this->logger($container)),
                default => new NullBroadcaster,
            };
        });

        $this->registerListening($container);
        $this->registerHub($container);
    }

    private function registerListening(ContainerInterface $container): void
    {
        $container->singleton(StreamToken::class, fn () => new StreamToken(
            $container->get(Signer::class),
            $this->clock($container),
        ));

        // Shared: the app registers its topics at boot, the token endpoint asks.
        $container->singleton(TopicPolicy::class, fn () => new TopicPolicy($this->logger($container)));
    }

    private function registerHub(ContainerInterface $container): void
    {
        $container->singleton(HubConfig::class, function () use ($container) {
            return HubConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(HubStatus::class, fn () => new RedisHubStatus(
            fn () => RedisConnection::open($this->redis($container)),
            $container->get(HubConfig::class)->statusKey,
            $this->logger($container),
        ));

        $container->singleton(HubHealthCheck::class, fn () => new HubHealthCheck($container->get(HubStatus::class)));

        $container->singleton(HubFactory::class, fn () => new HubFactory(
            fn () => RedisSubscriber::over(
                $this->redis($container),
                $container->get(HubConfig::class)->channelPrefix . '*',
                $this->clock($container),
                $this->logger($container),
            ),
            $container->get(StreamToken::class),
            $container->get(HubConfig::class),
            $this->clock($container),
            $this->logger($container),
            $container->get(HubStatus::class),
        ));

        $container->singleton(SseServeCommand::class, fn () => new SseServeCommand(
            $container->get(HubConfig::class),
            $container->get(HubFactory::class)->server(...),
            $this->logger($container),
        ));
    }

    /** The cache's Redis settings, whatever CACHE_STORE says: open() reads only where the server is. */
    private function redis(ContainerInterface $container): CacheConfig
    {
        return CacheConfig::fromEnvironment($container->get(Environment::class));
    }

    private function clock(ContainerInterface $container): ClockInterface
    {
        return $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock;
    }

    private function logger(ContainerInterface $container): LoggerInterface
    {
        return $container->bound(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger;
    }
}
