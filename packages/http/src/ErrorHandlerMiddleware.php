<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The error boundary: the single authority that turns a throwable into a
 * response, so every failure gets a consistent shape.
 *
 * Not the outermost middleware, deliberately. The ones that stamp headers on
 * the way back out — the security headers, the policy — have to sit OUTSIDE
 * this one, or the response it renders for an error is the one response in the
 * application that carries neither. What that costs is that a throwable from
 * one of those few escapes the boundary; {@see HttpKernel::panic()} answers
 * that with a logged plain-text 500 rather than letting it reach the client.
 *
 * Everything that can actually fail — the router, the session, the body parser,
 * the database, every controller — belongs inside it.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ErrorRendererInterface $renderer,
        private readonly bool $debug = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            return $this->render($e, $request, $e->status(), $e->headers());
        } catch (Throwable $e) {
            return $this->render($e, $request, 500);
        }
    }

    /**
     * Log the error if it is a fault (5xx), delegate rendering, then apply any
     * headers the error mapped (e.g. Allow on a 405)
     *
     * @param array<string, string> $headers
     */
    private function render(Throwable $e, ServerRequestInterface $request, int $status, array $headers = []): ResponseInterface
    {
        if ($status >= 500) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        }

        $response = $this->renderer->render(new ErrorContext($e, $request, $status, $this->debug));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
