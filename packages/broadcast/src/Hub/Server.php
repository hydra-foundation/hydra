<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\Envelope;
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
    private const HEAD_ROOM = 64;

    /** @var array<int, Stream> keyed by socket id */
    private array $streams = [];

    private bool $running = false;
    private readonly int $startedAt;
    private ?int $lastStatus = null;

    /** @param resource $listener */
    public function __construct(
        private readonly mixed $listener,
        private readonly Subscriber $subscriber,
        private readonly StreamToken $tokens,
        private readonly HubConfig $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?HubStatus $status = null,
    ) {
        stream_set_blocking($this->listener, false);
        $this->startedAt = $this->now();
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running && is_resource($this->listener)) {
            $this->tick(1.0);
        }
    }

    /** Ends run() after the round in progress, and closes every stream. */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->streams as $stream) {
            $this->close($stream);
        }

        $this->subscriber->close();

        if (is_resource($this->listener)) {
            fclose($this->listener);
        }

        $this->status?->clear();
    }

    /** Open event streams. */
    public function streams(): int
    {
        return count(array_filter($this->streams, static fn (Stream $s): bool => $s->isStreaming()));
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

        $read = [$this->listener];
        $write = [];
        $redis = $this->subscriber->socket();

        if ($redis !== null) {
            $read[] = $redis;
        }

        foreach ($this->streams as $stream) {
            $read[] = $stream->socket;

            if ($stream->outbox !== '') {
                $write[] = $stream->socket;
            }
        }

        $except = null;
        $seconds = (int) $timeout;

        // A signal interrupts the wait, which is not an error: the round ends early.
        if (@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1_000_000)) === false) {
            return;
        }

        foreach ($read as $socket) {
            match (true) {
                $socket === $this->listener => $this->accept(),
                $socket === $redis => $this->fanOut($this->subscriber->read()),
                default => $this->receive($this->streams[(int) $socket] ?? null),
            };
        }

        foreach ($write as $socket) {
            if (isset($this->streams[(int) $socket])) {
                $this->flush($this->streams[(int) $socket]);
            }
        }

        $this->sweep();
        $this->report();
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

        match ($token === '' ? TokenState::Invalid : $this->tokens->inspect($token)) {
            TokenState::Invalid => $this->refuse($stream, 403),
            TokenState::Expired => $this->refuse($stream, 204),
            TokenState::Valid => $this->open($stream, $token),
        };
    }

    private function open(Stream $stream, string $token): void
    {
        if ($this->streams() >= $this->config->maxConnections) {
            $this->refuse($stream, 503);

            return;
        }

        $grant = $this->tokens->open($token);

        // Valid a moment ago; only a clock that moved in between says otherwise.
        if ($grant === null) {
            $this->refuse($stream, 204);

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

    /** Says the hub is alive, every STATUS_EVERY seconds, for three times as long. */
    private function report(): void
    {
        $now = $this->now();

        if ($this->status === null || ($this->lastStatus !== null && $now - $this->lastStatus < HubConfig::STATUS_EVERY)) {
            return;
        }

        $this->lastStatus = $now;
        $this->status->publish(
            new HubReport((int) getmypid(), $this->startedAt, $this->streams(), $this->subscriber->subscribed(), $now),
            HubConfig::STATUS_EVERY * 3,
        );
    }

    private function close(Stream $stream): void
    {
        unset($this->streams[(int) $stream->socket]);

        if (is_resource($stream->socket)) {
            fclose($stream->socket);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
