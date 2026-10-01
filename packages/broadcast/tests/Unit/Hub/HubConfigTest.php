<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Tests\Unit\WritesEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HubConfig::class)]
final class HubConfigTest extends TestCase
{
    use WritesEnvironment;

    public function test_the_defaults(): void
    {
        $config = new HubConfig;

        $this->assertSame('0.0.0.0', $config->host);
        $this->assertSame(8080, $config->port);
        $this->assertSame(1000, $config->maxConnections);
        $this->assertSame(15, $config->heartbeat);
        $this->assertSame(900, $config->tokenTtl);
        $this->assertSame('broadcast.', $config->channelPrefix);
        $this->assertSame('sse:hub', $config->statusKey);
    }

    public function test_the_environment(): void
    {
        $config = HubConfig::fromEnvironment($this->environment([
            'SSE_LISTEN' => '127.0.0.1:9000',
            'SSE_MAX_CONNECTIONS' => '50',
            'SSE_HEARTBEAT' => '5',
            'STREAM_TOKEN_TTL' => '600',
            'REDIS_PREFIX' => 'hydra:',
        ]));

        $this->assertSame(['127.0.0.1', 9000, 50, 5, 600], [$config->host, $config->port, $config->maxConnections, $config->heartbeat, $config->tokenTtl]);
        $this->assertSame('hydra:broadcast.', $config->channelPrefix);
        $this->assertSame('hydra:sse:hub', $config->statusKey);
    }

    public function test_an_empty_environment_gives_the_defaults(): void
    {
        $this->assertEquals(new HubConfig, HubConfig::fromEnvironment($this->environment([])));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function refused(): iterable
    {
        yield 'no port' => [['SSE_LISTEN' => '0.0.0.0'], 'SSE_LISTEN must be host:port; got "0.0.0.0".'];
        yield 'port zero' => [['SSE_LISTEN' => '0.0.0.0:0'], 'SSE_LISTEN must be host:port; got "0.0.0.0:0".'];
        yield 'port too big' => [['SSE_LISTEN' => 'x:65536'], 'SSE_LISTEN must be host:port; got "x:65536".'];
        yield 'no host' => [['SSE_LISTEN' => ':8080'], 'SSE_LISTEN must be host:port; got ":8080".'];
        yield 'no connections' => [['SSE_MAX_CONNECTIONS' => '0'], 'SSE_MAX_CONNECTIONS must be at least 1; got 0.'];
        yield 'no heartbeat' => [['SSE_HEARTBEAT' => '0'], 'SSE_HEARTBEAT must be at least 1; got 0.'];
        yield 'no ttl' => [['STREAM_TOKEN_TTL' => '0'], 'STREAM_TOKEN_TTL must be at least 1; got 0.'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_by_name(array $env, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        HubConfig::fromEnvironment($this->environment($env));
    }
}
