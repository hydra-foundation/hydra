<?php

declare(strict_types=1);

namespace Hydra\Authorization;

use Hydra\Authorization\Contracts\GateInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authorized middleware
 *
 * Enforces a single ability before a route runs, the authorization counterpart
 * to auth's AuthenticateMiddleware. A denied request never reaches the
 * controller: the gate throws a 403 which the app's outermost ErrorHandlerMiddleware renders.
 */
abstract class AuthorizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly GateInterface $gate) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Throws a 403 when denied, which propagates past the controller; returns
        // silently when allowed, and the request continues inward.
        $this->gate->authorize($this->ability());

        return $handler->handle($request);
    }

    /**
     * The ability this middleware enforces.
     */
    abstract protected function ability(): string;
}
