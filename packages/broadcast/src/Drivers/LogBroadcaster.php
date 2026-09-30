<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Drivers;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Envelope;
use Psr\Log\LoggerInterface;

/** Writes each publish to the log, one info line, so it can be seen before anything listens. */
final readonly class LogBroadcaster implements BroadcasterInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function publish(string $topic, string $event, array $data = []): void
    {
        (new Envelope($topic, $event, $data, 0))->toJson();

        $this->logger->info("Broadcast {$event} on {$topic}", ['topic' => $topic, 'event' => $event, 'data' => $data]);
    }
}
