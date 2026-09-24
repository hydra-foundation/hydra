<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Exceptions\ServiceUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses every request with a 503 while the application is down, ahead of the
 * rate limiter, the session and the database — the things a maintenance window
 * is usually for. {@see HealthMiddleware} sits above it, so a probe still
 * reports on the dependencies.
 *
 * Renders the refusal itself rather than throwing it to the error handler: that
 * logs and reports every 5xx as a fault, and a deploy is not one, once per
 * request for as long as it takes.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly ErrorRendererInterface $renderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $down = $this->maintenance->current();

        if ($down === null) {
            return $handler->handle($request);
        }

        $refusal = new ServiceUnavailableException($down['message'], $down['retry']);
        $response = $this->renderer->render(new ErrorContext($refusal, $request, $refusal->status(), debug: false));

        foreach ($refusal->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
