<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\HubCapacityException;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
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
        $this->assertNull($config->maxConnections, 'unset: the poller decides');
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

    public function test_unset_it_is_a_thousand_where_the_poller_has_no_limit(): void
    {
        $this->assertSame(1000, (new HubConfig)->for(self::unlimited())->maxConnections);
    }

    public function test_unset_it_is_what_select_can_watch_on_older_php(): void
    {
        $this->assertSame(SelectPoller::CAPACITY, (new HubConfig)->for(new SelectPoller)->maxConnections);
    }

    public function test_select_can_watch_streams_below_its_descriptor_ceiling(): void
    {
        // 1024 descriptors, less the hub's own and its room for request heads.
        $this->assertSame(896, SelectPoller::CAPACITY);
    }

    public function test_a_value_select_can_watch_is_kept(): void
    {
        $this->assertSame(896, (new HubConfig(maxConnections: 896))->for(new SelectPoller)->maxConnections);
    }

    public function test_a_value_over_what_select_can_watch_is_refused_with_both_fixes(): void
    {
        $this->expectException(HubCapacityException::class);
        $this->expectExceptionMessage(
            'SSE_MAX_CONNECTIONS=897 is more than this PHP can watch with stream_select (896). '
            . 'Lower it to 896 or less, or run the hub on PHP 8.6, which waits through Io\\Poll and has no such limit.',
        );

        (new HubConfig(maxConnections: 897))->for(new SelectPoller);
    }

    public function test_a_poller_without_a_limit_takes_any_value(): void
    {
        $this->assertSame(5000, (new HubConfig(maxConnections: 5000))->for(self::unlimited())->maxConnections);
    }

    public function test_resolving_keeps_every_other_setting(): void
    {
        $config = new HubConfig(host: '127.0.0.1', port: 9000, heartbeat: 5, tokenTtl: 600, channelPrefix: 'p.', statusKey: 's');

        $this->assertEquals(
            new HubConfig(host: '127.0.0.1', port: 9000, maxConnections: 1000, heartbeat: 5, tokenTtl: 600, channelPrefix: 'p.', statusKey: 's'),
            $config->for(self::unlimited()),
        );
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

    private static function unlimited(): Poller
    {
        return new class implements Poller {
            public function watch(mixed $socket): void {}

            public function forget(mixed $socket): void {}

            public function wantWrite(mixed $socket, bool $want): void {}

            /** @return array{read: list<resource>, write: list<resource>} */
            public function wait(float $timeout): array
            {
                return ['read' => [], 'write' => []];
            }

            public function name(): string
            {
                return 'epoll';
            }

            public function capacity(): ?int
            {
                return null;
            }
        };
    }
}
