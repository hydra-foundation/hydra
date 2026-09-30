<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Contracts;

use InvalidArgumentException;

/**
 * Tells other processes that something changed. A broadcast is a nudge, such
 * as "users changed" or "you have 3 unread", not a way to move records: anyone
 * allowed to listen on the topic reads the data.
 *
 * Delivery is best effort. Nobody listening, or a broker that is down, loses
 * the message, and the caller carries on: a live update is never worth a
 * failed write.
 */
interface BroadcasterInterface
{
    /**
     * Tell anyone listening on $topic that $event happened.
     *
     * @param array<string, mixed> $data JSON-encodable, at most 64 KiB encoded
     * @throws InvalidArgumentException for a bad topic, event name or payload
     */
    public function publish(string $topic, string $event, array $data = []): void;
}
