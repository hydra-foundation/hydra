<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Drivers;

use Closure;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Envelope;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publishes each envelope on its own channel, {prefix}{topic}, for the hub to
 * pick up with a pattern subscribe.
 *
 * Best effort, as pub/sub is: a Redis that is down is logged and swallowed, so
 * the write that published still succeeds. The connection opens on the first
 * publish and is kept for the process; after a failure it is dropped, and the
 * next publish opens a fresh one rather than reusing a broken socket.
 */
final class RedisBroadcaster implements BroadcasterInterface
{
    private ?Channel $channel = null;

    /** @param Closure(): Channel $open */
    public function __construct(
        private readonly Closure $open,
        private readonly string $channelPrefix,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {}

    public function publish(string $topic, string $event, array $data = []): void
    {
        // Before any connection: a bad topic or payload is the caller's
        // mistake, and throws whether or not Redis is up.
        $json = (new Envelope($topic, $event, $data, (int) $this->clock->now()->format('Uv')))->toJson();

        try {
            $this->channel ??= ($this->open)();
            $this->channel->publish($this->channelPrefix . $topic, $json);
        } catch (RuntimeException $e) {
            $this->channel = null;
            $this->logger->warning(
                "Could not broadcast {$event} on {$topic}: {$e->getMessage()}",
                ['topic' => $topic, 'event' => $event, 'exception' => $e],
            );
        }
    }
}
