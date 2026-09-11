<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware pipeline
 *
 * The request travels inward through each middleware to the innermost
 * handler (the kernel/router); the response unwinds back outward. Each
 * middleware receives a handler representing "the rest of the pipeline".
 *
 * The chain is rebuilt from the immutable middleware list on every dispatch,
 * so a single instance can safely handle many requests.
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private readonly array $middleware;

    /**
     * @param iterable<MiddlewareInterface> $middleware  outermost first
     * @param RequestHandlerInterface       $kernel      the innermost handler
     */
    public function __construct(iterable $middleware, private readonly RequestHandlerInterface $kernel)
    {
        $this->middleware = array_values(
            is_array($middleware) ? $middleware : iterator_to_array($middleware, false)
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Wrap from the innermost handler outward
        $handler = $this->kernel;

        foreach (array_reverse($this->middleware) as $middleware) {
            $handler = $this->wrap($middleware, $handler);
        }

        return $handler->handle($request);
    }

    /** Adapt a middleware + its delegate into a single request handler. */
    private function wrap(MiddlewareInterface $middleware, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
