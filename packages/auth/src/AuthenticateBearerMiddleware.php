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
 * token's owner, under the API prefixes only. A bearer request anywhere else,
 * or with a token that is not accepted, is refused: a client that sent
 * credentials wants to know they failed, and a browser page needs the session
 * a bearer request never starts. Every other request passes to the session.
 */
final class AuthenticateBearerMiddleware implements MiddlewareInterface
{
    /** @param list<string> $paths path prefixes a token is accepted under */
    public function __construct(
        private readonly RequestGuard $guard,
        private readonly ApiTokens $tokens,
        private readonly array $paths = ['/api/'],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('/^Bearer(?:\s+(.*))?$/is', $request->getHeaderLine('Authorization'), $match) !== 1) {
            return $handler->handle($request);
        }

        if (!$this->covers($request->getUri()->getPath())) {
            throw new AuthenticationException(
                'A bearer token is accepted on the API only.',
                headers: ['WWW-Authenticate' => 'Bearer error="invalid_request"'],
            );
        }

        $authenticated = $this->tokens->authenticate(trim($match[1] ?? ''));

        if ($authenticated === null) {
            throw new AuthenticationException(headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }

        $this->guard->useToken($authenticated);

        return $handler->handle($request->withAttribute(ApiToken::class, $authenticated->token));
    }

    private function covers(string $path): bool
    {
        foreach ($this->paths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
