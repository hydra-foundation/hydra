<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets the origins in {@see CorsConfig} call the paths it covers. A preflight
 * is answered here, before routing. Place it above the error handler, so an
 * error's body stays readable to the page that caused it.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CorsConfig $config,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled() || !$this->config->covers($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && $this->config->allows($origin);

        if (
            strtoupper($request->getMethod()) === 'OPTIONS'
            && $origin !== ''
            && $request->hasHeader('Access-Control-Request-Method')
        ) {
            $response = $this->responses->createResponse(204);

            if ($allowed) {
                $response = $this->allowOrigin($response, $origin)
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config->allowedMethods))
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config->allowedHeaders))
                    ->withHeader('Access-Control-Max-Age', (string) $this->config->maxAge);
            }

            return $response->withAddedHeader('Vary', 'Origin');
        }

        $response = $handler->handle($request);

        if ($allowed) {
            $response = $this->allowOrigin($response, $origin);

            if ($this->config->exposedHeaders !== []) {
                $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config->exposedHeaders));
            }
        }

        return $response->withAddedHeader('Vary', 'Origin');
    }

    private function allowOrigin(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Origin', $this->config->wildcard() ? '*' : $origin);
    }
}
