<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Core\Contracts\HealthCheckInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Answers `GET /up` itself: 200 when every check passes, 503 when any fails,
 * and a body that names each check and says ok or down — never why, since this
 * URL is public. The reason goes to the log.
 *
 * A middleware rather than a route so it can sit ahead of the session, the
 * rate limiter and activity recording: a load balancer polling every few
 * seconds would otherwise open a session per probe, spend a client budget,
 * and fill the activity table.
 */
final class HealthMiddleware implements MiddlewareInterface
{
    public const PATH = '/up';

    /** @var array<string, HealthCheckInterface> */
    private readonly array $checks;

    private readonly LoggerInterface $logger;

    /**
     * @param list<HealthCheckInterface> $checks
     */
    public function __construct(
        private readonly Responder $responder,
        array $checks = [],
        ?LoggerInterface $logger = null,
        private readonly string $path = self::PATH,
    ) {
        $named = [];

        foreach ($checks as $check) {
            if (isset($named[$check->name()])) {
                throw new InvalidArgumentException("Two health checks are both named \"{$check->name()}\".");
            }

            $named[$check->name()] = $check;
        }

        $this->checks = $named;
        $this->logger = $logger ?? new NullLogger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->path || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $results = array_map($this->run(...), $this->checks);
        $healthy = !in_array('down', $results, true);

        return $this->responder
            ->json(
                ['status' => $healthy ? 'ok' : 'down', 'checks' => (object) $results],
                $healthy ? Status::Ok : Status::ServiceUnavailable,
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    private function run(HealthCheckInterface $check): string
    {
        try {
            $check->check();

            return 'ok';
        } catch (Throwable $e) {
            $this->logger->warning("Health check {$check->name()} failed: {$e->getMessage()}", ['exception' => $e]);

            return 'down';
        }
    }
}
