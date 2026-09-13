<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps a set of conservative security headers onto every response.
 *
 * What it stamps is a constructor argument rather than a fixed list, because
 * the right set depends on what else the application sends: a policy carrying
 * frame-ancestors already says what X-Frame-Options says, and HSTS belongs to
 * {@see ForceHttpsMiddleware}, which knows whether the request arrived over TLS.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * The set for an application with no Content-Security-Policy.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /**
     * The set for an application whose policy carries frame-ancestors.
     *
     * X-Frame-Options is the superseded spelling of that directive, and every
     * browser that reads the policy ignores the header. Sending both is not
     * harmful, only two places to state one rule — and two places to disagree,
     * since the header cannot express an allow-list the directive can.
     *
     * @var array<string, string>
     */
    public const WITH_CSP = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /** @var array<string, string> Header name => value, applied to every response. */
    private readonly array $headers;

    /** @param array<string, string>|null $headers null takes {@see self::DEFAULTS} */
    public function __construct(?array $headers = null)
    {
        $this->headers = $headers ?? self::DEFAULTS;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
