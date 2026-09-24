<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Redis;
use RedisException;
use RuntimeException;

/**
 * Opens the connection RedisStore runs on. It is its own class because every
 * line of it is a failure mode: phpredis reports some of them by throwing and
 * others by returning false, and a half-opened connection is worse than none at
 * all. A Redis object that never authenticated still answers every command,
 * with an error, and a counter read off an error reply is zero, which is under
 * every limit there is.
 */
final class RedisConnection
{
    public static function open(CacheConfig $config): Redis
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException(
                'CACHE_STORE=redis requires ext-redis. Install it, or set CACHE_STORE=array'
                . ' (single-process only, see Hydra\Cache\ArrayStore).'
            );
        }

        $redis = new Redis;
        $where = sprintf('%s:%d', $config->host, $config->port);

        try {
            // Silenced: phpredis raises a warning beside the exception it throws,
            // and a displayed warning sends the headers, so a 503 goes out as 200.
            if (@$redis->connect($config->host, $config->port, $config->timeout) === false) {
                throw new RuntimeException("Could not connect to Redis at {$where}.");
            }

            // The connect timeout covers the handshake and nothing after it.
            // Without this, a server that accepts the connection and then stops
            // answering holds the worker until the socket dies, which is the
            // failure the timeout is there to prevent. With a limiter on every
            // request, one such server takes the whole fpm pool with it.
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $config->readTimeout);

            // The password never appears in the message: this one reaches a log.
            if ($config->password !== '' && $redis->auth($config->password) === false) {
                throw new RuntimeException("Redis at {$where} rejected the configured password.");
            }

            if ($config->database !== 0 && $redis->select($config->database) === false) {
                throw new RuntimeException("Redis at {$where} has no database {$config->database}.");
            }
        } catch (RedisException $e) {
            throw new RuntimeException(
                sprintf('Could not open the Redis connection to %s: %s', $where, $e->getMessage()),
                previous: $e,
            );
        }

        return $redis;
    }
}
