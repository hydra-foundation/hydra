<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\CacheConfig;
use Hydra\Core\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CacheConfigTest extends TestCase
{
    /** The REDIS_* keys these tests clear so a .env can be read in isolation. */
    private const KEYS = [
        'CACHE_STORE',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',
        'REDIS_DATABASE',
        'REDIS_PREFIX',
        'REDIS_TIMEOUT',
    ];

    private string $dir;

    /** @var array<string, string> the real environment's values, put back in tearDown */
    private array $borrowed = [];

    /** @var list<string> keys this test's .env exported to the process env */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-cache-' . uniqid('', true);
        mkdir($this->dir);

        // These keys may be set for real — RedisStoreTest reads REDIS_HOST to
        // find something to test against. Clearing them without putting them
        // back would silently skip that suite rather than fail here.
        foreach (self::KEYS as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $this->borrowed[$key] = $value;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        foreach ($this->borrowed as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        $this->borrowed = [];

        $file = $this->dir . '/.env';

        if (file_exists($file)) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    public function test_it_defaults_to_redis_because_counters_must_be_shared(): void
    {
        $config = $this->fromEnv("APP_NAME=hydra\n");

        $this->assertSame(CacheConfig::REDIS, $config->driver);
        $this->assertSame(6379, $config->port);
        $this->assertSame(0, $config->database);
    }

    public function test_it_maps_the_redis_environment_keys(): void
    {
        $config = $this->fromEnv(
            "CACHE_STORE=redis\n" .
            "REDIS_HOST=redis\n" .
            "REDIS_PORT=6380\n" .
            "REDIS_PASSWORD=s3cret\n" .
            "REDIS_DATABASE=3\n" .
            "REDIS_PREFIX=hydra:\n"
        );

        $this->assertSame('redis', $config->host);
        $this->assertSame(6380, $config->port);
        $this->assertSame('s3cret', $config->password);
        $this->assertSame(3, $config->database);
        $this->assertSame('hydra:', $config->prefix);
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache driver must be one of');

        new CacheConfig(driver: 'memcached');
    }

    public function test_a_blocking_timeout_is_refused(): void
    {
        // phpredis reads 0 as "wait forever", which turns an unreachable Redis
        // into a hung request instead of a failed one.
        $this->expectException(InvalidArgumentException::class);

        new CacheConfig(timeout: 0);
    }

    public function test_a_negative_database_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheConfig(database: -1);
    }

    private function fromEnv(string $contents): CacheConfig
    {
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        file_put_contents($this->dir . '/.env', $contents);

        foreach (explode("\n", $contents) as $line) {
            if (str_contains($line, '=')) {
                $this->written[] = trim(explode('=', $line, 2)[0]);
            }
        }

        return CacheConfig::fromEnvironment(new Environment($this->dir));
    }
}
