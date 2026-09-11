<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\RedisStore;
use Redis;
use RedisException;

/**
 * The same contract against a real Redis. Skipped where there isn't one, so the
 * suite still runs on a bare checkout — but the store that production uses is
 * only actually covered where this runs.
 */
final class RedisStoreTest extends StoreContractTestCase
{
    private Redis $redis;
    private RedisStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        $redis = new Redis;

        try {
            $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 1.0);
        } catch (RedisException $e) {
            $this->markTestSkipped('No Redis to test against: ' . $e->getMessage());
        }

        $this->redis = $redis;
        // A prefix per test keeps concurrent runs, and the application's own
        // keys, out of each other's way.
        $this->store = new RedisStore($redis, 'hydra-test:' . uniqid('', true) . ':');
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
