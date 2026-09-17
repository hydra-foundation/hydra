<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Http\Middleware;

use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A per-route budget, which is the only way to see that route middleware runs
 * inside the router and counts against a name of its own.
 */
final readonly class LoginThrottleMiddleware implements MiddlewareInterface
{
    public const ATTEMPTS = 5;
    public const WINDOW = 600;

    public function __construct(private RateLimiter $limiter) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->limiter->enforce($request, new RateLimitPolicy('login', self::ATTEMPTS, self::WINDOW));

        return $handler->handle($request);
    }
}
