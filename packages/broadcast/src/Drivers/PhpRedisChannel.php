<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Drivers;

use Redis;
use RedisException;
use RuntimeException;

/**
 * A Channel over an open phpredis connection.
 *
 * @internal
 */
final readonly class PhpRedisChannel implements Channel
{
    public function __construct(private Redis $redis) {}

    public function publish(string $channel, string $message): void
    {
        try {
            $result = $this->redis->publish($channel, $message);
        } catch (RedisException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        if ($result === false) {
            throw new RuntimeException('Redis refused the PUBLISH.');
        }
    }
}
