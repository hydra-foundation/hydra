<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Force HTTPS middleware
 *
 * Forces every request onto HTTPS when the app opts in
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    /** One year, and apply to subdomains — the conventional HSTS baseline. */
    private const HSTS = 'max-age=31536000; includeSubDomains';

    public function __construct(
        private readonly bool $enabled,
        private readonly Responder $respond,
        private readonly bool $trustForwardedProto = false,
        private readonly ?ClientIpResolver $clients = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        if (!$this->isSecure($request)) {
            // Drop any explicit port: an http URL may carry :80, which is wrong
            // for https — clearing it lets the default 443 apply.
            $secureUrl = $request->getUri()->withScheme('https')->withPort(null);

            return $this->respond->redirect((string) $secureUrl, Status::MovedPermanently);
        }

        return $handler->handle($request)
            ->withHeader('Strict-Transport-Security', self::HSTS);
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        // The forwarded scheme is only meaningful when the app has declared
        // that a proxy it controls sets it (TLS terminated upstream). With no
        // proxy, the header is attacker-controlled — consulting it here would
        // let any direct client spoof its way past the redirect.
        if (!$this->trustForwardedProto) {
            return false;
        }

        // Declaring the proxies narrows the opt-in from "trust this header" to
        // "trust this header from these peers", which is the only form of it
        // that survives a request arriving directly rather than through them.
        if ($this->clients !== null && !$this->clients->acceptsForwardingFrom($request)) {
            return false;
        }

        return strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
