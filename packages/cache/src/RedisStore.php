<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Cache\Contracts\StoreInterface;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Redis store
 *
 * The store every php-fpm worker shares, which is what makes a per-client
 * budget mean one budget rather than one per worker.
 *
 * Every key is written under a configured prefix so several applications can
 * share a Redis instance without counting each other's requests.
 */
final class RedisStore implements StoreInterface
{
    /**
     * INCRBY, then set the window only if the key has none.
     *
     * The check and the write have to happen together: between a separate INCR
     * and EXPIRE, a second request can increment the same key and find a TTL
     * that is not there yet, leaving a counter that never expires — or, worse,
     * one that both requests re-arm until the limit is unreachable. Redis runs
     * a script atomically, so the pair cannot be interleaved.
     *
     * TTL returns -1 for a key with no expiry and -2 for one that does not
     * exist; either means "no window yet", hence < 0.
     */
    private const INCREMENT = <<<'LUA'
        local total = redis.call('INCRBY', KEYS[1], ARGV[1])
        if tonumber(ARGV[2]) > 0 and redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        return total
        LUA;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = '',
    ) {}

    public function get(string $key): mixed
    {
        $value = $this->call(fn (): mixed => $this->redis->get($this->prefix . $key));

        // phpredis reports a missing key as false, which is also a storable
        // value; serialization keeps them apart, so only a literal miss is null.
        return $value === false ? null : $this->decode((string) $value);
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $encoded = $this->encode($value);

        $this->call(function () use ($key, $encoded, $ttl): void {
            if ($ttl > 0) {
                $this->redis->setex($this->prefix . $key, $ttl, $encoded);

                return;
            }

            $this->redis->set($this->prefix . $key, $encoded);
        });
    }

    public function forget(string $key): void
    {
        $this->call(fn () => $this->redis->del($this->prefix . $key));
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $total = $this->call(fn (): mixed => $this->redis->eval(
            self::INCREMENT,
            [$this->prefix . $key, (string) $by, (string) $ttl],
            1,
        ));

        return (int) $total;
    }

    public function ttl(string $key): int
    {
        $ttl = (int) $this->call(fn (): mixed => $this->redis->ttl($this->prefix . $key));

        // -1 (no expiry) and -2 (no key) both mean "nothing is going to clear".
        return $ttl < 0 ? 0 : $ttl;
    }

    /**
     * A store that cannot be reached must fail loudly. Anything counting
     * against it would otherwise carry on with a count of zero, which reads as
     * "under the limit" for every request that arrives while Redis is down.
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $e) {
            throw new RuntimeException('Cache store is unavailable: ' . $e->getMessage(), previous: $e);
        }
    }

    private function encode(mixed $value): string
    {
        // Integers go in bare so INCRBY can read them back; Redis counters are
        // plain decimal strings, and a serialized one would not increment.
        return is_int($value) ? (string) $value : serialize($value);
    }

    private function decode(string $value): mixed
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        $decoded = @unserialize($value);

        // unserialize() returns false both for the literal `false` and for junk;
        // comparing against its own encoding tells them apart.
        return $decoded === false && $value !== serialize(false) ? $value : $decoded;
    }
}
