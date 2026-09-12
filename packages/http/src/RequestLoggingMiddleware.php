<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes one access-log line per request: method, path, status, duration.
 */
final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        $this->logger->info('request handled', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return $response;
    }
}
