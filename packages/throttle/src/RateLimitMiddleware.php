<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The application-wide budget, spent once per request.
 *
 * It belongs INSIDE the error handler, not above it. A store that cannot be
 * reached raises, and above the handler that raise reaches the emitter with no
 * response to show; inside it, the same failure renders as a 500 like any
 * other. The refusal itself is a TooManyRequestsException for the same reason:
 * the error renderer already knows how to answer a browser, a JSON client and
 * an htmx swap, so a 429 needs no presentation of its own.
 *
 * A tighter budget for one route is a middleware of its own on that route, not
 * a special case here. This one holds a single policy because every request
 * pays into it.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $limiter,
        private RateLimitPolicy $policy,
        private bool $enabled = true,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->enabled) {
            $this->limiter->enforce($request, $this->policy);
        }

        return $handler->handle($request);
    }
}
