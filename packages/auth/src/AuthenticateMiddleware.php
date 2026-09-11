<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Exceptions\AuthenticationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticate middleware
 *
 * Guards a route: lets the request through only when a user is authenticated,
 * otherwise throws a 401 {@see AuthenticationException} before the controller runs.
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly GuardInterface $guard) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->guard->check()) {
            throw new AuthenticationException;
        }

        return $handler->handle($request);
    }
}
