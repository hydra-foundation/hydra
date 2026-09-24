<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Closure;
use Hydra\Cache\Contracts\StoreInterface;
use Redis;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * The store every php-fpm worker shares, which is what makes a per-client
 * budget mean one budget rather than one per worker. Every key is written under
 * a configured prefix so several applications can
 * share a Redis instance without counting each other's requests.
 *
 * Given an opener rather than a connection, it connects on the first command
 * and not when it is built. The pipeline builds every middleware up front, so a
 * store that connected on construction took the whole application down with
 * Redis, the health endpoint included, before anything could catch it.
 */
final class RedisStore implements StoreInterface
{
    /**
     * INCRBY, then set the window only if the key has none.
     *
     * The check and the write have to happen together: between a separate INCR
     * and EXPIRE, a second request can increment the same key and find a TTL
     * that is not there yet, leaving a counter that never expires, or one that
     * both requests re-arm until the limit is unreachable. Redis runs a script
     * atomically, so the pair cannot be interleaved.
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

    private ?Redis $redis;

    /** @var (Closure(): Redis)|null */
    private ?Closure $opener;

    private ?Throwable $unreachable = null;

    /**
     * @param Redis|(Closure(): Redis) $redis a connection, or what opens one on first use
     */
    public function __construct(
        Redis|Closure $redis,
        private readonly string $prefix = '',
    ) {
        $this->redis = $redis instanceof Redis ? $redis : null;
        $this->opener = $redis instanceof Closure ? $redis : null;
    }

    public function get(string $key): mixed
    {
        $value = $this->call(fn (Redis $redis): mixed => $redis->get($this->prefix . $key));

        // phpredis reports a missing key as false, which is also a storable
        // value; serialization keeps them apart, so only a literal miss is null.
        return $value === false ? null : $this->decode((string) $value);
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $encoded = $this->encode($value);

        $this->call(function (Redis $redis) use ($key, $encoded, $ttl): void {
            if ($ttl > 0) {
                $redis->setex($this->prefix . $key, $ttl, $encoded);

                return;
            }

            $redis->set($this->prefix . $key, $encoded);
        });
    }

    public function forget(string $key): void
    {
        $this->call(fn (Redis $redis) => $redis->del($this->prefix . $key));
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $total = $this->call(fn (Redis $redis): mixed => $redis->eval(
            self::INCREMENT,
            [$this->prefix . $key, (string) $by, (string) $ttl],
            1,
        ));

        return $this->counter($total, 'INCRBY ' . $key);
    }

    public function ttl(string $key): int
    {
        $ttl = $this->counter($this->call(fn (Redis $redis): mixed => $redis->ttl($this->prefix . $key)), 'TTL ' . $key);

        // -1 (no expiry) and -2 (no key) both mean "nothing is going to clear".
        return $ttl < 0 ? 0 : $ttl;
    }

    /**
     * Every key this store wrote, and nothing else. FLUSHDB would take the
     * sessions and any other application sharing the instance with it, so the
     * prefix is the boundary, and without one there is nothing to tell our
     * keys from theirs, so that case is a refusal rather than a guess.
     *
     * SCAN rather than KEYS: the counters are one key per client per window, so
     * the set is large exactly when the server can least afford a blocking scan
     * of the whole keyspace.
     */
    public function flush(): void
    {
        if ($this->prefix === '') {
            throw new RuntimeException(
                'Refusing to flush a RedisStore with no prefix: every other key in the database'
                . ' would go with it. Configure REDIS_PREFIX.'
            );
        }

        $cursor = null;

        do {
            // By reference: phpredis advances the cursor through the argument,
            // and an arrow function would only ever hand it a copy of null.
            $keys = $this->call(function (Redis $redis) use (&$cursor): mixed {
                return $redis->scan($cursor, $this->prefix . '*', 1000);
            });

            // A page of the keyspace that matched nothing is normal, and it is
            // not the end of the scan: only the cursor says that.
            if (is_array($keys) && $keys !== []) {
                $this->call(fn (Redis $redis) => $redis->del($keys));
            }
        } while ((int) $cursor !== 0);
    }

    /**
     * A store that cannot be reached must fail loudly. Anything counting
     * against it would otherwise carry on with a count of zero, which reads as
     * "under the limit" for every request that arrives while Redis is down.
     *
     * An unreachable server throws. A server that answers with an error does
     * not: OOM under maxmemory, READONLY on a demoted replica, NOAUTH and
     * WRONGTYPE all come back as false with the message left in getLastError(),
     * which is cleared before the command so it can be read after it.
     */
    private function call(callable $operation): mixed
    {
        $redis = $this->connection();

        try {
            $redis->clearLastError();
            $result = $operation($redis);
        } catch (RedisException $e) {
            throw new RuntimeException('Cache store is unavailable: ' . $e->getMessage(), previous: $e);
        }

        $error = $redis->getLastError();

        if ($error !== null) {
            $redis->clearLastError();

            throw new RuntimeException('Cache store refused the command: ' . $error);
        }

        return $result;
    }

    /**
     * A failed open is kept and thrown again rather than retried: the session,
     * the limiter and the page would otherwise each wait out the connect
     * timeout in turn, and one request would stall for several of them.
     */
    private function connection(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if ($this->unreachable !== null) {
            throw $this->unreachable;
        }

        try {
            return $this->redis = ($this->opener)();
        } catch (Throwable $e) {
            throw $this->unreachable = $e;
        }
    }

    /**
     * A counter reply that is not an integer is a failure, and casting one is
     * how a limiter fails open: `(int) false` is 0, which reads as "no requests
     * yet" for every caller that arrives while the store is answering errors.
     */
    private function counter(mixed $reply, string $command): int
    {
        if (!is_int($reply)) {
            throw new RuntimeException(sprintf(
                'Cache store returned %s for %s, expected an integer.',
                get_debug_type($reply),
                $command,
            ));
        }

        return $reply;
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
