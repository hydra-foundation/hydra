<?php

declare(strict_types=1);

namespace Hydra\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every response's caching, on its way out. Three things, in order:
 *
 * 1. A response that says nothing about caching gets `no-store`, so nothing
 *    becomes cacheable unless a controller asks (see HttpCache).
 * 2. A `public` response that sets a cookie becomes `private`: a shared cache
 *    must never keep someone's session. The native session's cookie is not
 *    on the PSR-7 response (PHP queues it itself at session_start()), so
 *    PHP's header queue is read too.
 * 3. A GET or HEAD 200 whose validators match the request becomes a 304 with
 *    no body. Not while the Release says otherwise (development).
 *
 * Place it outside the session and the error handler, so it sees every
 * response, error pages and fresh cookies included.
 */
final class HttpCacheMiddleware implements MiddlewareInterface
{
    /** Representation headers a 304 leaves out (RFC 9110 §15.4.5). */
    private const CONTENT = ['Content-Type', 'Content-Length', 'Content-Range', 'Transfer-Encoding'];

    /** @var Closure(): list<string> */
    private readonly Closure $queued;

    /** @param (Closure(): list<string>)|null $queued PHP's own header queue; headers_list() unless a test stands in */
    public function __construct(
        private readonly StreamFactoryInterface $streams,
        private readonly ?Release $release = null,
        private readonly ?LoggerInterface $logger = null,
        ?Closure $queued = null,
    ) {
        $this->queued = $queued ?? static fn (): array => array_values(headers_list());
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$response->hasHeader('Cache-Control')) {
            return $response->withHeader('Cache-Control', 'no-store');
        }

        $response = $this->keepSessionsPrivate($request, $response);

        if ($this->release?->conditional === false || $response->getStatusCode() !== 200) {
            return $response;
        }

        if (!ConditionalGet::fresh($request, $response->getHeaderLine('ETag'), $response->getHeaderLine('Last-Modified'))) {
            return $response;
        }

        $notModified = $response->withStatus(304)->withBody($this->streams->createStream(''));

        foreach (self::CONTENT as $header) {
            $notModified = $notModified->withoutHeader($header);
        }

        return $notModified;
    }

    private function keepSessionsPrivate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $directives = array_map(trim(...), explode(',', $response->getHeaderLine('Cache-Control')));
        $public = array_search('public', array_map(strtolower(...), $directives), true);

        if ($public === false || !$this->setsCookie($response)) {
            return $response;
        }

        $directives[$public] = 'private';
        $this->logger?->debug('A public response set a cookie, so it was sent as private: {path}', [
            'path' => $request->getUri()->getPath(),
        ]);

        return $response->withHeader('Cache-Control', implode(', ', $directives));
    }

    private function setsCookie(ResponseInterface $response): bool
    {
        if ($response->hasHeader('Set-Cookie')) {
            return true;
        }

        foreach (($this->queued)() as $header) {
            if (stripos($header, 'set-cookie:') === 0) {
                return true;
            }
        }

        return false;
    }
}
