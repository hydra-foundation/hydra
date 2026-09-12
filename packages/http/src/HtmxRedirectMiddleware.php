<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rewrites a redirect into the form htmx acts on. htmx fetches, so the browser
 * follows a 3xx itself and htmx swaps the redirect target's whole body into one
 * element instead of navigating. htmx 4 reads no response header to say
 * otherwise and skips a 204 entirely, so what makes it navigate is a directive
 * in a body it will look at. Normalising here rather than at each call site
 * means a handler returns a plain redirect and cannot forget.
 */
final class HtmxRedirectMiddleware implements MiddlewareInterface
{
    /** Redirects the client follows via Location. 304 carries none. */
    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(private readonly Responder $respond) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (
            !Htmx::fromRequest($request)->isHtmx()
            || !in_array($response->getStatusCode(), self::REDIRECTS, true)
            || !$response->hasHeader('Location')
        ) {
            return $response;
        }

        return $this->respond->htmx()
            ->redirect($response->getHeaderLine('Location'))
            ->applyTo($this->respond->html(''));
    }
}
