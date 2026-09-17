<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Http\Middleware;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps someone already signed in off the login page.
 */
final class RedirectAuthenticatedMiddleware implements MiddlewareInterface
{
    private const HOME_PATH = '/admin';

    public function __construct(
        private readonly GuardInterface $guard,
        private readonly Responder $respond,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->guard->check()) {
            return $handler->handle($request);
        }

        return $this->respond->redirect(self::HOME_PATH);
    }
}
