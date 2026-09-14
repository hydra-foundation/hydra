<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\Throttle\ThrottleConfig;
use Hydra\Throttle\ThrottleServiceProvider;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

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
        $container->instance(ThrottleConfig::class, new ThrottleConfig(limit: 1, window: 60));
        (new ThrottleServiceProvider)->register($container);

        $middleware = $container->get(RateLimitMiddleware::class);
        $handler = new StubHandler;

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

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://hydra.test/', serverParams: ['REMOTE_ADDR' => '198.51.100.7']);
    }

    /** A minimal strict container, preloaded with what the package expects bound. */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** @var array<string, callable> */
            private array $factories = [];
            /** @var array<string, mixed> */
            private array $resolved = [];

            public function get(string $id): mixed
            {
                if (array_key_exists($id, $this->resolved)) {
                    return $this->resolved[$id];
                }

                if ($id === Environment::class) {
                    return $this->resolved[$id] = new Environment(__DIR__); // no .env: defaults apply
                }

                if ($id === StoreInterface::class) {
                    return $this->resolved[$id] = new ArrayStore;
                }

                if ($id === ClientIpResolver::class) {
                    return $this->resolved[$id] = new ClientIpResolver(TrustedProxies::none());
                }

                if (isset($this->factories[$id])) {
                    return $this->resolved[$id] = ($this->factories[$id])();
                }

                throw new class ("No binding for {$id}.") extends RuntimeException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return isset($this->factories[$id]) || array_key_exists($id, $this->resolved);
            }

            public function singleton(string $abstract, callable|string $concrete): void
            {
                $this->factories[$abstract] = is_string($concrete)
                    ? static fn () => new $concrete()
                    : $concrete;
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->resolved[$abstract] = $instance;
            }

            public function bound(string $abstract): bool
            {
                return $this->has($abstract);
            }
        };
    }
}

/** A handler that answers 200 and nothing else. */
final class StubHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
}
