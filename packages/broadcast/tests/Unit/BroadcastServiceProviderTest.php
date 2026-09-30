<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\BroadcastConfig;
use Hydra\Broadcast\BroadcastServiceProvider;
use Hydra\Broadcast\Console\SseServeCommand;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Drivers\NullBroadcaster;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\HubFactory;
use Hydra\Broadcast\Hub\HubHealthCheck;
use Hydra\Broadcast\Hub\HubReport;
use Hydra\Broadcast\Hub\HubStatus;
use Hydra\Broadcast\Hub\RedisHubStatus;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Environment;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(BroadcastServiceProvider::class)]
#[CoversClass(RedisHubStatus::class)]
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

    public function test_listen_tokens_are_signed_with_the_bound_signer(): void
    {
        $signer = Signer::fromHex(str_repeat('ab', 32));
        $container = $this->register([], [Signer::class => $signer]);

        $tokens = $container->get(StreamToken::class);

        $this->assertSame(7, $tokens->open($tokens->mint(7, ['demo'], 60))?->userId);
        $this->assertNull((new StreamToken(Signer::fromHex(str_repeat('cd', 32)), new SystemClock))->open($tokens->mint(7, ['demo'], 60)));
    }

    public function test_the_topic_policy_is_one_shared_registry(): void
    {
        $container = $this->register([]);
        $container->get(TopicPolicy::class)->allow('demo', static fn (): bool => true);

        $this->assertTrue($container->get(TopicPolicy::class)->permits(1, 'demo'));
    }

    public function test_the_hub_is_wired_from_the_environment_without_connecting(): void
    {
        $container = $this->register([
            'SSE_LISTEN' => '127.0.0.1:9123',
            'REDIS_PREFIX' => 'app:',
            'REDIS_PORT' => '1',
        ], [Signer::class => Signer::fromHex(str_repeat('ab', 32))]);

        $this->assertSame(9123, $container->get(HubConfig::class)->port);
        $this->assertInstanceOf(RedisHubStatus::class, $container->get(HubStatus::class));
        $this->assertInstanceOf(HubFactory::class, $container->get(HubFactory::class));
        $this->assertInstanceOf(SseServeCommand::class, $container->get(SseServeCommand::class));
        $this->assertInstanceOf(HubHealthCheck::class, $container->get(HubHealthCheck::class));
    }

    public function test_the_health_check_fails_when_redis_cannot_be_reached(): void
    {
        $this->withoutRealRedisAddress();
        $container = $this->register(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1', 'REDIS_TIMEOUT' => '0.2']);

        $this->expectException(RuntimeException::class);

        $container->get(HubHealthCheck::class)->check();
    }

    public function test_the_hub_status_logs_a_write_it_could_not_make(): void
    {
        $this->withoutRealRedisAddress();
        $logger = new CapturingLogger;
        $container = $this->register(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1', 'REDIS_TIMEOUT' => '0.2'], [LoggerInterface::class => $logger]);

        $status = $container->get(HubStatus::class);
        $status->publish(new HubReport(1, 1, 0, true, 1), 30);
        $status->clear();

        $this->assertCount(2, $logger->records());
        $this->assertStringStartsWith("Could not write the SSE hub's status: ", $logger->messages()[0]);
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
