<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Core\Contracts\HealthCheckInterface;
use RuntimeException;

/** Fails when no hub has reported in, or the one that has is cut off from Redis. */
final readonly class HubHealthCheck implements HealthCheckInterface
{
    public function __construct(private HubStatus $status) {}

    public function name(): string
    {
        return 'sse';
    }

    public function check(): void
    {
        $report = $this->status->read();

        if ($report === null) {
            throw new RuntimeException('The SSE hub is not running: start it with bin/console sse:serve (under Docker, the `sse` service).');
        }

        if (!$report->subscribed) {
            throw new RuntimeException('The SSE hub has lost its Redis subscription.');
        }
    }
}
