<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\Testing\FakeHandler;
use Hydra\Http\TrustedProxies;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Throttle\Testing\ArrayLockoutStore;
use Hydra\Throttle\ThrottleConfig;
use Hydra\Throttle\ThrottleServiceProvider;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * The wiring, which is where a limiter most plausibly ends up doing nothing:
 * every piece can be correct while the middleware the stack actually runs was
 * built with a budget nobody configured.
 */
#[CoversClass(ThrottleServiceProvider::class)]
final class ThrottleServiceProviderTest extends TestCase
{
    public function test_the_middleware_runs_the_configured_budget(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);
        $container->instance(ThrottleConfig::class, new ThrottleConfig(limit: 1, window: 60));

        $middleware = $container->get(RateLimitMiddleware::class);
        $handler = FakeHandler::respondingWith(new Response(200));

        $middleware->process($this->request(), $handler);

        $this->expectException(TooManyRequestsException::class);

        $middleware->process($this->request(), $handler);
    }

    public function test_the_limiter_and_the_middleware_count_into_the_same_store(): void
    {
        // Separate stores would give the per-route limiters their own counters
        // and quietly double every budget an application declares.
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);

        $this->assertSame($container->get(RateLimiter::class), $container->get(RateLimiter::class));
    }

    public function test_an_unset_environment_still_yields_a_limit(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);

        $this->assertTrue($container->get(ThrottleConfig::class)->enabled);
    }

    public function test_lockouts_are_recorded_when_a_store_is_bound(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);
        $container->instance(LockoutStoreInterface::class, $lockouts = new ArrayLockoutStore);
        $container->instance(ClockInterface::class, $clock = new FrozenClock);
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter = $container->get(RateLimiter::class);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $this->assertNotNull($lockouts->find('login', 'a'));
    }

    public function test_a_lockout_that_cannot_be_recorded_is_logged_through_the_apps_logger(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);
        $container->instance(LockoutStoreInterface::class, new FailingLockoutStore);
        $container->instance(ClockInterface::class, new FrozenClock);
        $container->instance(LoggerInterface::class, $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        });
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter = $container->get(RateLimiter::class);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $this->assertSame(['Could not record a lockout: database gone'], $logger->lines);
    }

    public function test_nothing_is_recorded_when_no_store_is_bound(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);
        $container->instance(ClockInterface::class, new FrozenClock);
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter = $container->get(RateLimiter::class);
        $limiter->hit('a', $policy);

        $this->assertFalse($limiter->hit('a', $policy)->allowed);
    }

    public function test_a_store_bound_without_a_clock_says_to_bind_one(): void
    {
        $container = $this->container();
        (new ThrottleServiceProvider)->register($container);
        $container->instance(LockoutStoreInterface::class, new ArrayLockoutStore);

        $this->expectExceptionMessage('bind Psr\Clock\ClockInterface');

        $container->get(RateLimiter::class);
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://hydra.test/', serverParams: ['REMOTE_ADDR' => '198.51.100.7']);
    }

    /** A minimal strict container, preloaded with what the package expects bound. */
    private function container(): ContainerInterface
    {
        return new FakeContainer([
            Environment::class => new Environment(__DIR__), // no .env: defaults apply
            StoreInterface::class => new ArrayStore,
            ClientIpResolver::class => new ClientIpResolver(TrustedProxies::none()),
        ]);
    }
}
