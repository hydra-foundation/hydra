<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * Cache settings, validated at construction so a bad value fails at boot
 * rather than at the first request that needed the store.
 */
final readonly class CacheConfig
{
    public const REDIS = 'redis';
    public const ARRAY = 'array';

    private const DRIVERS = [self::REDIS, self::ARRAY];

    public function __construct(
        public string $driver = self::REDIS,
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public string $password = '',
        public int $database = 0,
        public string $prefix = '',
        public float $timeout = 1.0,
        public float $readTimeout = 1.0,
    ) {
        // Same fail-loud discipline as SessionConfig: a driver nobody
        // implements would otherwise surface as a container error far from the
        // setting that caused it.
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Cache driver must be one of %s; got "%s".',
                implode(', ', self::DRIVERS),
                $driver,
            ));
        }

        if ($database < 0) {
            throw new InvalidArgumentException("Cache database must be >= 0; got {$database}.");
        }

        // A zero or negative connect timeout means "block forever" in phpredis,
        // which turns an unreachable Redis into a hung request rather than a
        // failed one.
        if ($timeout <= 0) {
            throw new InvalidArgumentException("Cache timeout must be greater than 0; got {$timeout}.");
        }

        // And the same again for the reply. A connect timeout only covers the
        // handshake: a server that accepts the connection and then stops
        // answering holds the worker until the socket dies, which with a
        // limiter on every request is the whole pool.
        if ($readTimeout <= 0) {
            throw new InvalidArgumentException("Cache read timeout must be greater than 0; got {$readTimeout}.");
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            driver: $env->string('CACHE_STORE', self::REDIS),
            host: $env->string('REDIS_HOST', '127.0.0.1'),
            port: $env->int('REDIS_PORT', 6379),
            password: $env->string('REDIS_PASSWORD', ''),
            database: $env->int('REDIS_DATABASE', 0),
            prefix: $env->string('REDIS_PREFIX', ''),
            timeout: (float) $env->string('REDIS_TIMEOUT', '1.0'),
            readTimeout: (float) $env->string('REDIS_READ_TIMEOUT', '1.0'),
        );
    }
}
