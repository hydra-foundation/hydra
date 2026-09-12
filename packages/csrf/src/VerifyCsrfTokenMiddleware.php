<?php

declare(strict_types=1);

namespace Hydra\Csrf;

use Hydra\Csrf\Exceptions\TokenMismatchException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the CSRF token on every state-changing request.
 */
final class VerifyCsrfTokenMiddleware implements MiddlewareInterface
{
    /**
     * The RFC 9110 §9.2.1 read-only methods. An allowlist rather than a
     * denylist, so a verb nobody here has heard of still needs a token.
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

    /** The header wins over the form field, so an htmx swap needs no hidden input. */
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
