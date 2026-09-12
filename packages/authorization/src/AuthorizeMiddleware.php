<?php

declare(strict_types=1);

namespace Hydra\Authorization;

use Hydra\Authorization\Contracts\GateInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces a single ability before a route runs, the authorization counterpart
 * to auth's AuthenticateMiddleware. A denial never reaches the controller: the
 * gate throws a 403 for ErrorHandlerMiddleware to render.
 */
abstract class AuthorizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly GateInterface $gate) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->gate->authorize($this->ability());

        return $handler->handle($request);
    }

    /** The class-string of an AbilityInterface for the gate to resolve. */
    abstract protected function ability(): string;
}
