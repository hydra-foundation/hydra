<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\CacheConfig;
use Hydra\Cache\RedisConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * The connection every counter runs over. What is checked here is not that it
 * connects, but that it refuses to hand back anything less than a usable
 * connection: the alternative is a Redis object that answers every command with
 * an error, and an error reply counted as zero is under every limit there is.
 */
#[CoversClass(RedisConnection::class)]
final class RedisConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->skipOrFail('ext-redis is not installed.');
        }
    }

    public function test_the_connection_has_a_finite_read_timeout(): void
    {
        // The failure a connect timeout does not cover: a server that completes
        // the handshake and then stops answering. phpredis leaves the read
        // timeout unlimited by default, so the worker waits for the socket to
        // die, and with a limiter on every request that is the whole pool.
        $redis = RedisConnection::open($this->config(readTimeout: 2.5));

        $this->assertSame(2.5, (float) $redis->getOption(Redis::OPT_READ_TIMEOUT));

        $redis->close();
    }

    public function test_a_port_with_nothing_behind_it_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        // The address belongs in the message: a cache misconfiguration reads as
        // a dead application, and this is the only line that says why.
        $this->expectExceptionMessage(':63999');

        RedisConnection::open($this->config(port: 63999));
    }

    public function test_a_refused_password_never_reaches_the_message(): void
    {
        // AUTH fails either way here: against a server with a password because
        // this is the wrong one, against a server without because it takes no
        // AUTH at all. Both end in a message that gets logged.
        $config = $this->config(password: 'not-the-password');

        $this->expectException(RuntimeException::class);

        try {
            RedisConnection::open($config);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString($config->password, $e->getMessage());

            throw $e;
        }
    }

    private function config(
        int $port = 0,
        string $password = '',
        float $readTimeout = 1.0,
    ): CacheConfig {
        return new CacheConfig(
            host: getenv('REDIS_HOST') ?: '127.0.0.1',
            port: $port !== 0 ? $port : (int) (getenv('REDIS_PORT') ?: 6379),
            password: $password,
            timeout: 1.0,
            readTimeout: $readTimeout,
        );
    }

    private function skipOrFail(string $why): never
    {
        if (getenv('REDIS_REQUIRED') !== false) {
            $this->fail($why . ' REDIS_REQUIRED is set, so this is a failure rather than a skip.');
        }

        $this->markTestSkipped($why);
    }
}
