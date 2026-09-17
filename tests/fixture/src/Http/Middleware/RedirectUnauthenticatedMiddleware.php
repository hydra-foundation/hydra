<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Http\Middleware;

use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Exceptions\TokenMismatchException;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns the two exceptions that mean "you are not signed in" into a redirect.
 *
 * The second is the subtle one and the reason this is a fixture route rather
 * than a framework default: a token mismatch on a request that was never issued
 * a token is an expired session, not an attack, and answering it with a 403
 * strands whoever left a form open.
 */
final class RedirectUnauthenticatedMiddleware implements MiddlewareInterface
{
    private const LOGIN_PATH = '/login';

    public function __construct(
        private readonly Responder $respond,
        private readonly CsrfGuard $csrf,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthenticationException) {
            return $this->respond->redirect(self::LOGIN_PATH);
        } catch (TokenMismatchException $e) {
            if ($this->csrf->issued()) {
                throw $e;
            }

            return $this->respond->redirect(self::LOGIN_PATH);
        }
    }
}
