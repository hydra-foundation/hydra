<?php

declare(strict_types=1);

namespace Hydra\Session;

use Hydra\Session\Contracts\SessionLifecycleInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Brackets the request with the session lifecycle: open it on the way in, save
 * it on the way out. A request carrying a bearer token gets no session at all,
 * and so no cookie: it authenticates by its header alone.
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionLifecycleInterface $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('/^Bearer(\s|$)/i', $request->getHeaderLine('Authorization')) === 1) {
            return $handler->handle($request);
        }

        $this->session->start();

        try {
            return $handler->handle($request);
        } finally {
            $this->session->save();
        }
    }
}
