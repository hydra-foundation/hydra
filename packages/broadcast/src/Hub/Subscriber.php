<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\Envelope;

/**
 * Where the hub hears broadcasts. The server selects on socket() alongside
 * the browsers, calls read() when it is readable, and maintain() every round
 * so a lost connection comes back.
 */
interface Subscriber
{
    /** @return resource|null the socket to wait on, or null while disconnected */
    public function socket();

    /** @return list<Envelope> what arrived, in order */
    public function read(): array;

    /** Connects, or reconnects once the backoff has passed. */
    public function maintain(): void;

    /** True once after each confirmed subscription: events may have been missed before it. */
    public function resubscribed(): bool;

    public function subscribed(): bool;

    public function close(): void;
}
