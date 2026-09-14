<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\Throttle\RateLimitPolicy;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A handler that records whether it was ever reached. */
final class CountingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return new Response(200);
    }
}

/**
 * The middleware is thin on purpose, so what is worth testing is what it does
 * NOT do: reach the handler once the budget is gone, and count anything at all
 * when the limiter is switched off.
 */
#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    public function test_a_request_within_the_budget_reaches_the_handler(): void
    {
        $handler = new CountingHandler;
        $response = $this->middleware(limit: 2)->process($this->request(), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $handler->calls);
    }

    public function test_the_handler_is_never_reached_once_the_budget_is_gone(): void
    {
        // The point of limiting at this depth: the work being protected must
        // not happen, so the refusal has to come before the handler runs.
        $handler = new CountingHandler;
        $middleware = $this->middleware(limit: 1);

        $middleware->process($this->request(), $handler);

        try {
            $middleware->process($this->request(), $handler);
            $this->fail('The request past the limit reached the handler.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->status());
            $this->assertSame(1, $handler->calls);
        }
    }

    public function test_a_disabled_limiter_counts_nothing(): void
    {
        $handler = new CountingHandler;
        $middleware = $this->middleware(limit: 1, enabled: false);

        $middleware->process($this->request(), $handler);
        $middleware->process($this->request(), $handler);

        $this->assertSame(2, $handler->calls);
    }

    private function middleware(int $limit, bool $enabled = true): RateLimitMiddleware
    {
        $limiter = new RateLimiter(new ArrayStore, new ClientIpResolver(TrustedProxies::none()));

        return new RateLimitMiddleware($limiter, new RateLimitPolicy('global', $limit, 60), $enabled);
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://hydra.test/', serverParams: ['REMOTE_ADDR' => '198.51.100.7']);
    }
}
