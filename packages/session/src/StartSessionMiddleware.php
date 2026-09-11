<?php

declare(strict_types=1);

namespace Hydra\Session;

use Hydra\Session\Contracts\SessionLifecycleInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Start session middleware
 *
 * Brackets the request with the session lifecycle: open it on the way in, save
 * it on the way out.
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionLifecycleInterface $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();

        try {
            return $handler->handle($request);
        } finally {
            $this->session->save();
        }
    }
}
