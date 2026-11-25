<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\Poll\DescriptorCeilingException;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\TokenState;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The hub: one loop over a listening socket, every browser, and the Redis
 * subscription, fanning each broadcast out to the streams granted its topic.
 *
 * Everything is non-blocking, and every buffer is bounded: a browser that is
 * slow, silent or hostile costs the others nothing. tick() is one round of
 * the loop, which is what the tests drive; run() repeats it until stop().
 *
 * Tokens are never logged: one in a log line is a stream anyone can open.
 */
final class Server
{
    /** Connections accepted beyond the stream cap, for heads still being read. */
    public const HEAD_ROOM = 64;

    /** @var array<int, Stream> keyed by socket id */
    private array $streams = [];

    private bool $halted = false;
    private readonly int $startedAt;
    private ?int $lastStatus = null;
    private ?bool $lastSubscribed = null;
    private readonly Poller $poller;

    /** The settings with the stream cap settled for the poller: never null. */
    private readonly HubConfig $config;

    /** Rounds in a row the poller has refused for a descriptor past its ceiling. */
    private int $overCeiling = 0;

    /** @var resource|null the Redis socket being watched, swapped when it reconnects */
    private mixed $redis = null;

    /** @param resource $listener */
    public function __construct(
        private readonly mixed $listener,
        private readonly Subscriber $subscriber,
        private readonly StreamToken $tokens,
        HubConfig $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?HubStatus $status = null,
        ?Poller $poller = null,
    ) {
        stream_set_blocking($this->listener, false);
        $this->poller = $poller ?? new SelectPoller;
        $this->config = $config->for($this->poller);
        $this->poller->watch($this->listener);
        $this->startedAt = $this->now();
    }

    /** Serves until halt() or stop(), then closes everything. */
    public function run(): void
    {
        while (!$this->halted && is_resource($this->listener)) {
            $this->tick(1.0);
        }

        $this->stop();
    }

    /**
     * Asks run() to finish after the round in progress. Safe from a signal
     * handler, which may fire mid-select: it touches no socket.
     */
    public function halt(): void
    {
        $this->halted = true;
    }

    /** Closes every stream, the subscription and the listener, and clears the status. */
    public function stop(): void
    {
        $this->halted = true;

        foreach ($this->streams as $stream) {
            $this->close($stream);
        }

        $this->subscriber->close();

        if (is_resource($this->listener)) {
            $this->poller->forget($this->listener);
            fclose($this->listener);
        }

        $this->status?->clear();
    }

    /** Open event streams. */
    public function streams(): int
    {
        return count(array_filter($this->streams, static fn (Stream $s): bool => $s->isStreaming()));
    }

    /** Bytes held for browsers that have not read them yet, across every stream. */
    public function backlog(): int
    {
        return array_sum(array_map(static fn (Stream $s): int => strlen($s->outbox), $this->streams));
    }

    /** What the hub waits through: "epoll", "select", … */
    public function poller(): string
    {
        return $this->poller->name();
    }

    /** Every browser connection, streams and heads still being read alike. */
    public function connections(): int
    {
        return count($this->streams);
    }

    /** One round: wait up to $timeout seconds for anything to happen, then deal with it. */
    public function tick(float $timeout): void
    {
        if (!is_resource($this->listener)) {
            return;
        }

        $this->subscriber->maintain();

        // Events may have been published while the subscription was down.
        if ($this->subscriber->resubscribed()) {
            foreach ($this->streams as $stream) {
                if ($stream->isStreaming()) {
                    $this->send($stream, Frame::resync());
                }
            }
        }

        $redis = $this->watchRedis();

        try {
            $ready = $this->poller->wait($timeout);
        } catch (DescriptorCeilingException $e) {
            $this->shed($e);

            return;
        }

        $this->overCeiling = 0;

        // A signal interrupts the wait, which is not an error: the round ends early.
        if ($ready === null) {
            return;
        }

        foreach ($ready['read'] as $socket) {
            match (true) {
                $socket === $this->listener => $this->accept(),
                $socket === $redis => $this->fanOut($this->subscriber->read()),
                default => $this->receive($this->streams[(int) $socket] ?? null),
            };
        }

        foreach ($ready['write'] as $socket) {
            if (isset($this->streams[(int) $socket])) {
                $this->flush($this->streams[(int) $socket]);
            }
        }

        $this->sweep();
        $this->report();
    }

    /**
     * A socket is numbered past what the poller can watch, so no socket was
     * watched at all this round. Descriptors are handed out lowest first, so
     * the newest streams hold the high numbers: close one more of them each
     * round until a round succeeds. With none left the high one is Redis's,
     * reconnected while browsers held the low numbers: drop it, and it comes
     * back on a low one.
     */
    private function shed(DescriptorCeilingException $e): void
    {
        if ($this->overCeiling++ === 0) {
            $this->logger->error($e->getMessage() . ' Closed the newest streams to keep serving the rest. Lower SSE_MAX_CONNECTIONS, or run the hub on PHP 8.6, which has no such ceiling.');
        }

        $newest = array_slice(array_reverse($this->streams, true), 0, $this->overCeiling, true);

        if ($newest === []) {
            $this->subscriber->close();

            return;
        }

        foreach ($newest as $stream) {
            $this->close($stream);
        }
    }

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->listener, 0);

        if ($socket === false) {
            return;
        }

        // Past the cap with room for the heads being read, a connection is
        // not worth a file descriptor: nginx sees it close and says 502.
        if (count($this->streams) >= $this->config->maxConnections + self::HEAD_ROOM) {
            fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);
        $this->streams[(int) $socket] = new Stream($socket, $this->now());
        $this->poller->watch($socket);
    }

    /**
     * The subscriber's socket changes when it reconnects and is null while it
     * is down; the poller follows it.
     *
     * @return resource|null
     */
    private function watchRedis(): mixed
    {
        $redis = $this->subscriber->socket();

        if ($redis !== $this->redis) {
            if ($this->redis !== null) {
                $this->poller->forget($this->redis);
            }

            if ($redis !== null) {
                $this->poller->watch($redis);
            }

            $this->redis = $redis;
        }

        return $redis;
    }

    private function receive(?Stream $stream): void
    {
        if ($stream === null) {
            return;
        }

        $chunk = @fread($stream->socket, HubConfig::HEAD_LIMIT);

        if (($chunk === false || $chunk === '') && feof($stream->socket)) {
            $this->close($stream);

            return;
        }

        // A browser says nothing after its head; anything more is ignored.
        if ($stream->state !== Stream::READING) {
            return;
        }

        $stream->head .= (string) $chunk;

        if (RequestHead::complete($stream->head)) {
            $this->answer($stream, RequestHead::parse($stream->head));
        } elseif (strlen($stream->head) > HubConfig::HEAD_LIMIT) {
            $this->close($stream);
        }
    }

    private function answer(Stream $stream, ?RequestHead $head): void
    {
        $stream->head = '';

        if ($head === null) {
            $this->refuse($stream, 400);

            return;
        }

        if (!$head->isStream()) {
            $this->refuse($stream, 404);

            return;
        }

        $token = $head->token() ?? '';
        $grant = $token === '' ? null : $this->tokens->open($token);

        if ($grant === null) {
            // An honest page with an old token is sent for a fresh one; a
            // forgery is refused.
            $this->refuse($stream, $token !== '' && $this->tokens->inspect($token) === TokenState::Expired ? 204 : 403);

            return;
        }

        if ($this->streams() >= $this->config->maxConnections) {
            $this->refuse($stream, 503);

            return;
        }

        $stream->grant = $grant;
        $stream->state = Stream::STREAMING;
        $this->send($stream, Frame::preamble());

        $this->logger->debug("Opened a stream for user {$grant->userId} on " . implode(', ', $grant->topics) . '.');
    }

    /** @param 204|400|403|404|503 $status */
    private function refuse(Stream $stream, int $status): void
    {
        $stream->state = Stream::CLOSING;
        $this->send($stream, Frame::refuse($status));

        $this->logger->debug("Refused a stream with {$status}.");
    }

    /** @param list<Envelope> $envelopes */
    private function fanOut(array $envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $frame = Frame::event($envelope);

            foreach ($this->streams as $stream) {
                if ($stream->isStreaming() && $stream->grant?->allows($envelope->topic) === true) {
                    $this->send($stream, $frame);
                }
            }
        }
    }

    private function send(Stream $stream, string $bytes): void
    {
        $stream->outbox .= $bytes;
        $this->flush($stream);

        // Still streaming, and this far behind: the browser has stopped
        // reading, and holding more for it only costs memory.
        if (isset($this->streams[(int) $stream->socket]) && strlen($stream->outbox) > HubConfig::BACKLOG_LIMIT) {
            $this->logger->warning(sprintf('Dropped a stream more than %d bytes behind.', HubConfig::BACKLOG_LIMIT));
            $this->close($stream);
        }
    }

    private function flush(Stream $stream): void
    {
        if ($stream->outbox !== '') {
            $written = @fwrite($stream->socket, $stream->outbox);

            if ($written === false) {
                $this->close($stream);

                return;
            }

            $stream->outbox = substr($stream->outbox, $written);
            $stream->lastWrite = $this->now();
        }

        $this->poller->wantWrite($stream->socket, $stream->outbox !== '');

        if ($stream->outbox === '' && $stream->state === Stream::CLOSING) {
            $this->close($stream);
        }
    }

    /**
     * The per-round chores: close heads that took too long and streams whose
     * token has run out, and send a heartbeat down every stream that has been
     * quiet for the interval.
     */
    private function sweep(): void
    {
        $now = $this->now();

        foreach ($this->streams as $stream) {
            if ($stream->state === Stream::READING && $now - $stream->openedAt > HubConfig::HEAD_TIMEOUT) {
                $this->close($stream);
            } elseif ($stream->isStreaming() && $stream->grant !== null && $stream->grant->expiresAt < $now) {
                // The browser reconnects with the same token, is told 204,
                // and fetches a fresh one: this is how a sign-out arrives.
                $this->close($stream);
            } elseif ($stream->isStreaming() && $stream->outbox === '' && $now - $stream->lastWrite >= $this->config->heartbeat) {
                $this->send($stream, Frame::ping());
            }
        }
    }

    /**
     * Says the hub is alive, every STATUS_EVERY seconds for three times as
     * long, and at once when the Redis subscription is lost or regained, so
     * the card never trails the hub by a whole interval.
     */
    private function report(): void
    {
        $now = $this->now();
        $subscribed = $this->subscriber->subscribed();
        $due = $this->lastStatus === null
            || $now - $this->lastStatus >= HubConfig::STATUS_EVERY
            || $subscribed !== $this->lastSubscribed;

        if ($this->status === null || !$due) {
            return;
        }

        $this->lastStatus = $now;
        $this->lastSubscribed = $subscribed;
        $this->status->publish(
            new HubReport((int) getmypid(), $this->startedAt, $this->streams(), $subscribed, $now),
            HubConfig::STATUS_EVERY * 3,
        );
    }

    private function close(Stream $stream): void
    {
        unset($this->streams[(int) $stream->socket]);
        $this->poller->forget($stream->socket);

        if (is_resource($stream->socket)) {
            fclose($stream->socket);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
