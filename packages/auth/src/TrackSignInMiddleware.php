<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Http\ClientIpResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Says when and from where each sign-in was last seen: the address, through
 * the app's trusted proxies, and the user agent. The guard never sees the
 * request, so this reads it and hands it over.
 *
 * Runs after the handler, so the request that signs in is recorded with its
 * own address. Place it after the session and bearer middleware. A bearer
 * request is its token's, not a sign-in's, and is passed over. A failed write
 * is logged and swallowed: tracking observes a request, it never fails one.
 * With no sign-in store bound it does nothing.
 */
final class TrackSignInMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionGuard $guard,
        private readonly LoggerInterface $logger,
        private readonly ClientIpResolver $clients = new ClientIpResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($request->getAttribute(ApiToken::class) !== null) {
            return $response;
        }

        try {
            $agent = $request->getHeaderLine('User-Agent');

            $this->guard->seen($this->clients->resolve($request), $agent === '' ? null : $agent);
        } catch (Throwable $e) {
            $this->logger->warning('Could not record where a sign-in was seen: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $response;
    }
}
