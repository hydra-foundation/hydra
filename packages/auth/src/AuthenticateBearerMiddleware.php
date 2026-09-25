<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Exceptions\AuthenticationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates a request that carries `Authorization: Bearer <token>` as the
 * token's owner, and refuses one whose token is not accepted, whatever route
 * it asked for: a client that sent credentials wants to know they failed.
 * Every other request passes through untouched, to the session.
 */
final class AuthenticateBearerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestGuard $guard,
        private readonly ApiTokens $tokens,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('/^Bearer(?:\s+(.*))?$/is', $request->getHeaderLine('Authorization'), $match) !== 1) {
            return $handler->handle($request);
        }

        $authenticated = $this->tokens->authenticate(trim($match[1] ?? ''));

        if ($authenticated === null) {
            throw new AuthenticationException(headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }

        $this->guard->useToken($authenticated);

        return $handler->handle($request->withAttribute(ApiToken::class, $authenticated->token));
    }
}
