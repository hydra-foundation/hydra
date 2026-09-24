<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\RedisStore;
use Hydra\Cache\Testing\StoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use RedisException;
use RuntimeException;

/**
 * The same contract against a real Redis, plus the failures only a real server
 * produces. Skipped where there isn't one so the suite still runs on a bare
 * checkout, but CI sets REDIS_REQUIRED: the INCREMENT script and the error
 * replies below are the whole basis of the limiter, and a green build that
 * skipped them says nothing about the store that actually ships.
 */
#[CoversClass(RedisStore::class)]
final class RedisStoreTest extends StoreContractTestCase
{
    private Redis $redis;
    private RedisStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->unavailable('ext-redis is not installed.');
        }

        $redis = new Redis;

        try {
            if ($redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 1.0) === false) {
                $this->unavailable('No Redis to test against.');
            }
        } catch (RedisException $e) {
            $this->unavailable('No Redis to test against: ' . $e->getMessage());
        }

        $this->redis = $redis;
        // A prefix per test keeps concurrent runs, and the application's own
        // keys, out of each other's way.
        $this->store = new RedisStore($redis, 'hydra-test:' . uniqid('', true) . ':');
    }

    public function test_an_opener_runs_once_on_the_first_command(): void
    {
        $opened = 0;
        $store = new RedisStore(function () use (&$opened): Redis {
            $opened++;

            return $this->redis;
        }, 'hydra-test:' . uniqid('', true) . ':');

        $this->assertSame(0, $opened);

        $store->put('a', 'x', 10);
        $this->assertSame('x', $store->get('a'));
        $store->forget('a');

        $this->assertSame(1, $opened);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    protected function store(): StoreInterface
    {
        return $this->store;
    }

    /**
     * Really waits. The TTLs under test are the server's own, so there is no
     * clock here to move; this is the one place in the suite where the wall
     * clock is the thing being measured.
     */
    protected function advance(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * A missing Redis is a skip on a bare checkout and a failure in CI. The
     * whole point of this suite is that the shipped store is exercised
     * somewhere, and a skip that nobody notices is how it went uncovered.
     */
    private function unavailable(string $why): never
    {
        if (getenv('REDIS_REQUIRED') !== false) {
            $this->fail($why . ' REDIS_REQUIRED is set, so this is a failure rather than a skip.');
        }

        $this->markTestSkipped($why);
    }

    public function test_an_error_reply_is_not_a_count_of_zero(): void
    {
        // The fail-open the limiter cannot survive. phpredis answers an error
        // reply with false, and (int) false is 0, which reads as "no requests
        // yet" for every caller that arrives while the store is in that state.
        // WRONGTYPE stands in here for the ones a live server produces: OOM
        // under maxmemory, READONLY on a demoted replica, NOAUTH.
        $this->store->put('hits', 'not a number');

        $this->expectException(RuntimeException::class);

        $this->store->increment('hits', 1, ttl: 60);
    }

    public function test_a_store_with_no_prefix_refuses_to_flush(): void
    {
        // Without a prefix there is nothing to tell this application's keys
        // from the sessions, or from another application sharing the instance.
        $store = new RedisStore($this->redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_PREFIX');

        $store->flush();
    }

    public function test_flush_leaves_another_prefix_alone(): void
    {
        $mine = new RedisStore($this->redis, 'app-one:');
        $theirs = new RedisStore($this->redis, 'app-two:');

        $mine->increment('hits', 1, ttl: 60);
        $theirs->increment('hits', 1, ttl: 60);

        $mine->flush();

        $this->assertNull($mine->get('hits'));
        $this->assertSame(1, $theirs->get('hits'));

        $theirs->flush();
    }

    public function test_keys_are_written_under_the_configured_prefix(): void
    {
        // Two applications sharing one Redis must not count each other's hits.
        $one = new RedisStore($this->redis, 'app-one:');
        $two = new RedisStore($this->redis, 'app-two:');

        $one->increment('hits', 1, ttl: 30);
        $one->increment('hits', 1, ttl: 30);
        $two->increment('hits', 1, ttl: 30);

        $this->assertSame(2, $one->get('hits'));
        $this->assertSame(1, $two->get('hits'));

        $one->forget('hits');
        $two->forget('hits');
    }
}
