<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Redis;
use RedisException;
use RuntimeException;

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

            return new RedisStore($this->connect($config), $config->prefix);
        });
    }

    private function connect(CacheConfig $config): Redis
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException(
                'CACHE_STORE=redis requires ext-redis. Install it, or set CACHE_STORE=array'
                . ' (single-process only — see Hydra\Cache\ArrayStore).'
            );
        }

        $redis = new Redis;

        try {
            $redis->connect($config->host, $config->port, $config->timeout);

            if ($config->password !== '') {
                $redis->auth($config->password);
            }

            if ($config->database !== 0) {
                $redis->select($config->database);
            }
        } catch (RedisException $e) {
            throw new RuntimeException(
                sprintf('Could not connect to Redis at %s:%d: %s', $config->host, $config->port, $e->getMessage()),
                previous: $e,
            );
        }

        return $redis;
    }
}
