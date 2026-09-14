<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\CacheServiceProvider;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\RedisStore;
use Hydra\Cache\Tests\Support\TestContainer;
use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RedisException;
use RuntimeException;

/**
 * The wiring, which decides which store the whole application counts into. The
 * driver choice is the interesting half: an array store bound where redis was
 * configured gives every worker its own counters, and multiplies every limit
 * resting on them by the worker count.
 */
#[CoversClass(CacheServiceProvider::class)]
final class CacheServiceProviderTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-cache-env-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment exports .env values to the real process environment,
        // and the real environment beats the file, so scrub every key this
        // test wrote or a stale export leaks into the next test.
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    public function test_the_array_driver_binds_a_process_local_store(): void
    {
        $container = $this->register(['CACHE_STORE' => 'array']);

        $this->assertInstanceOf(ArrayStore::class, $container->get(StoreInterface::class));
    }

    public function test_the_store_is_shared_for_the_request(): void
    {
        // Two stores would mean the limiter and the middleware count into
        // separate tallies, which is a doubled budget rather than an error.
        $container = $this->register(['CACHE_STORE' => 'array']);

        $this->assertSame(
            $container->get(StoreInterface::class),
            $container->get(StoreInterface::class),
        );
    }

    public function test_the_config_reads_the_environment(): void
    {
        $container = $this->register([
            'CACHE_STORE' => 'array',
            'REDIS_PREFIX' => 'hydra:',
            'REDIS_DATABASE' => '3',
        ]);

        $config = $container->get(CacheConfig::class);

        $this->assertSame('array', $config->driver);
        $this->assertSame('hydra:', $config->prefix);
        $this->assertSame(3, $config->database);
    }

    public function test_an_unset_environment_asks_for_redis(): void
    {
        // The default is the shared store, not the convenient one: a
        // deployment that forgot to configure the cache should fail on the
        // connection rather than quietly run per-worker counters.
        $container = $this->register([]);

        $this->assertSame(CacheConfig::REDIS, $container->get(CacheConfig::class)->driver);
    }

    public function test_nothing_connects_until_the_store_is_asked_for(): void
    {
        // A Redis outage must not stop the application serving pages that
        // never touch the cache, which only holds while this stays a closure.
        $container = $this->register(['REDIS_PORT' => '1']);

        $this->assertFalse($container->isResolved(StoreInterface::class));
    }

    public function test_the_redis_driver_binds_a_redis_store(): void
    {
        if (!extension_loaded('redis')) {
            $this->unavailable('ext-redis is not installed.');
        }

        $container = $this->register([
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'REDIS_PORT' => getenv('REDIS_PORT') ?: '6379',
            'REDIS_PREFIX' => 'hydra-test:' . uniqid('', true) . ':',
        ]);

        try {
            $store = $container->get(StoreInterface::class);
        } catch (RedisException | RuntimeException $e) {
            $this->unavailable('No Redis to test against: ' . $e->getMessage());
        }

        $this->assertInstanceOf(RedisStore::class, $store);
    }

    /** @param array<string, string> $env */
    private function register(array $env): TestContainer
    {
        $container = new TestContainer([Environment::class => $this->environment($env)]);
        (new CacheServiceProvider)->register($container);

        return $container;
    }

    /**
     * Environment reads a .env file, so the settings under test are written to
     * one rather than pushed through putenv().
     *
     * @param array<string, string> $values
     */
    private function environment(array $values): Environment
    {
        $lines = '';
        foreach ($values as $key => $value) {
            $this->written[] = $key;
            $lines .= "{$key}={$value}\n";
        }

        file_put_contents($this->dir . '/.env', $lines);

        return new Environment($this->dir);
    }

    /** A missing Redis is a skip on a bare checkout and a failure in CI. */
    private function unavailable(string $why): never
    {
        if (getenv('REDIS_REQUIRED') !== false) {
            $this->fail($why . ' REDIS_REQUIRED is set, so this is a failure rather than a skip.');
        }

        $this->markTestSkipped($why);
    }
}
