<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\BroadcastConfig;
use Hydra\Broadcast\BroadcastServiceProvider;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Drivers\NullBroadcaster;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(BroadcastServiceProvider::class)]
final class BroadcastServiceProviderTest extends TestCase
{
    use WritesEnvironment;

    public function test_an_unconfigured_app_gets_the_null_driver(): void
    {
        $this->assertInstanceOf(NullBroadcaster::class, $this->register([])->get(BroadcasterInterface::class));
    }

    public function test_the_log_driver_writes_to_the_bound_logger(): void
    {
        $logger = new CapturingLogger;
        $container = $this->register(['BROADCAST_DRIVER' => 'log'], [LoggerInterface::class => $logger]);

        $broadcaster = $container->get(BroadcasterInterface::class);
        $broadcaster->publish('users', 'changed');

        $this->assertInstanceOf(LogBroadcaster::class, $broadcaster);
        $this->assertSame(['Broadcast changed on users'], $logger->messages());
    }

    public function test_the_log_driver_works_with_no_logger_bound(): void
    {
        $broadcaster = $this->register(['BROADCAST_DRIVER' => 'log'])->get(BroadcasterInterface::class);
        $broadcaster->publish('users', 'changed');

        $this->assertInstanceOf(LogBroadcaster::class, $broadcaster);
    }

    public function test_the_config_and_broadcaster_are_shared(): void
    {
        $container = $this->register(['BROADCAST_DRIVER' => 'log']);

        $this->assertSame($container->get(BroadcastConfig::class), $container->get(BroadcastConfig::class));
        $this->assertSame($container->get(BroadcasterInterface::class), $container->get(BroadcasterInterface::class));
    }

    public function test_nothing_is_built_at_register(): void
    {
        $container = $this->register(['BROADCAST_DRIVER' => 'redis', 'REDIS_PORT' => '1']);

        $this->assertFalse($container->isResolved(BroadcasterInterface::class));
        $this->assertFalse($container->isResolved(BroadcastConfig::class));
    }

    public function test_the_redis_driver_resolves_without_connecting_even_with_the_array_cache(): void
    {
        // Broadcasting over Redis does not depend on the cache using it.
        $container = $this->register([
            'BROADCAST_DRIVER' => 'redis',
            'CACHE_STORE' => 'array',
            'REDIS_PORT' => '1',
        ]);

        $this->assertInstanceOf(RedisBroadcaster::class, $container->get(BroadcasterInterface::class));
    }

    public function test_an_unreachable_redis_is_one_warning_and_no_exception(): void
    {
        // Port 1 has nothing behind it; without ext-redis the opener fails
        // earlier, naming the extension. Either way the publish returns.
        $this->withoutRealRedisAddress();
        $logger = new CapturingLogger;
        $container = $this->register([
            'BROADCAST_DRIVER' => 'redis',
            'CACHE_STORE' => 'array',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '1',
            'REDIS_TIMEOUT' => '0.2',
        ], [LoggerInterface::class => $logger]);

        $this->expectOutputString('');
        $container->get(BroadcasterInterface::class)->publish('users', 'changed');

        $this->assertCount(1, $logger->records());
        $this->assertSame('warning', $logger->records()[0]['level']);
        $this->assertStringStartsWith('Could not broadcast changed on users: ', $logger->messages()[0]);
    }

    /**
     * @param array<string, string> $env
     * @param array<string, object> $bound
     */
    private function register(array $env, array $bound = []): FakeContainer
    {
        $container = new FakeContainer([Environment::class => $this->environment($env)] + $bound);
        (new BroadcastServiceProvider)->register($container);

        return $container;
    }
}
