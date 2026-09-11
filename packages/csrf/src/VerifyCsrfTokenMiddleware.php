<?php

declare(strict_types=1);

namespace Hydra\Csrf;

use Hydra\Csrf\Exceptions\TokenMismatchException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verify CSRF token middlware
 *
 * Verifies the CSRF token on every state-changing request
 */
final class VerifyCsrfTokenMiddleware implements MiddlewareInterface
{
    /**
     * The RFC 9110 §9.2.1 safe (read-only) methods, exempt from the token
     * check. Anything NOT in this list requires a valid token — an allowlist
     * of known-safe verbs fails closed for verbs we have never heard of.
     */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CsrfGuard $guard) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            !in_array(strtoupper($request->getMethod()), self::SAFE, true)
            && !$this->guard->validate($this->submittedToken($request))
        ) {
            throw new TokenMismatchException;
        }

        return $handler->handle($request);
    }

    /**
     * The token the client submitted: the header if present, else the form
     * field, else null (nothing submitted — which never validates).
     */
    private function submittedToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine(CsrfGuard::HEADER);
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[CsrfGuard::FIELD]) && is_string($body[CsrfGuard::FIELD])) {
            return $body[CsrfGuard::FIELD];
        }

        return null;
    }
}
