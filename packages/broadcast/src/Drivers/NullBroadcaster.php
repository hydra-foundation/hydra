<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Drivers;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Envelope;

/**
 * Publishes nowhere, and still validates: a bad topic that only Redis in
 * production would refuse is refused here too.
 */
final readonly class NullBroadcaster implements BroadcasterInterface
{
    public function publish(string $topic, string $event, array $data = []): void
    {
        (new Envelope($topic, $event, $data, 0))->toJson();
    }
}
