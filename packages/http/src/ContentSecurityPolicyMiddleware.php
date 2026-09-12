<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Compiles the policy against this request's nonce and stamps it on the way
 * out. Reading the nonce after the handler has run is deliberate: the page
 * mints it while it renders, and both sides end up naming the same token.
 */
final class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
    private const ENFORCE = 'Content-Security-Policy';

    private const REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    public function __construct(
        private readonly ContentSecurityPolicy $policy,
        private readonly CspNonce $nonce,
        private readonly bool $enabled = true,
        private readonly bool $reportOnly = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->enabled) {
            return $response;
        }

        $header = $this->reportOnly ? self::REPORT_ONLY : self::ENFORCE;

        // A response that already carries a policy set it on purpose — a screen
        // relaxing one directive for itself — and re-stamping would undo it.
        if ($response->hasHeader($header)) {
            return $response;
        }

        return $response->withHeader($header, $this->policy->compile($this->nonce->value()));
    }
}
