<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request an id, on the request as an attribute, in
 * {@see RequestId} for code without the request, and on the response header.
 *
 * An incoming `X-Request-Id` is client-supplied, so it is adopted only when the
 * app has said a proxy it controls overwrites it — the bundled nginx passes its
 * own `$request_id` — and, with proxies declared, only from one of them.
 * Anything else gets a fresh id. Outermost in the stack, so the access log line
 * and an error response both carry it.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    /** Printable, header-safe, and short enough that a log line stays a line. */
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function __construct(
        private readonly RequestId $current,
        private readonly bool $trustIncoming = false,
        private readonly ?ClientIpResolver $clients = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->incoming($request) ?? RequestId::generate();

        $this->current->set($id);

        return $handler->handle($request->withAttribute(RequestId::ATTRIBUTE, $id))
            ->withHeader(RequestId::HEADER, $id);
    }

    private function incoming(ServerRequestInterface $request): ?string
    {
        if (!$this->trustIncoming) {
            return null;
        }

        if ($this->clients !== null && !$this->clients->acceptsForwardingFrom($request)) {
            return null;
        }

        $id = $request->getHeaderLine(RequestId::HEADER);

        return preg_match(self::PATTERN, $id) === 1 ? $id : null;
    }
}
