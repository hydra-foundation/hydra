<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Closure;
use Hydra\Broadcast\Envelope;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A pattern subscription on Redis, spoken in RESP over a raw socket.
 *
 * phpredis's subscribe() blocks until its callback says stop, so it cannot
 * share a select loop with the browsers. A subscriber only ever reads arrays
 * of bulk strings and integers, which is little enough to parse by hand.
 *
 * A lost connection is retried after 1, 2, 4, … up to 30 seconds, and a
 * confirmed subscription resets the wait.
 */
final class RedisSubscriber implements Subscriber
{
    /** The most unparsed bytes held: an envelope is at most 64 KiB. */
    public const BUFFER_LIMIT = 1048576;

    private const MAX_BACKOFF = 30;

    /** What parse() returns when the reply has not all arrived: an object no reply can be. */
    private static ?object $incomplete = null;

    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';
    private bool $subscribed = false;
    private bool $resubscribed = false;
    private int $backoff = 1;
    private ?int $nextAttempt = null;

    /** @param Closure(): resource $connect opens the socket, or throws a RuntimeException */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $pattern,
        private readonly string $password,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function socket()
    {
        return $this->socket;
    }

    public function subscribed(): bool
    {
        return $this->subscribed;
    }

    public function resubscribed(): bool
    {
        $was = $this->resubscribed;
        $this->resubscribed = false;

        return $was;
    }

    public function maintain(): void
    {
        if ($this->socket !== null || ($this->nextAttempt !== null && $this->now() < $this->nextAttempt)) {
            return;
        }

        try {
            $socket = ($this->connect)();
        } catch (RuntimeException $e) {
            $this->logger->warning("Could not subscribe to Redis: {$e->getMessage()}; retrying in {$this->backoff}s.");
            $this->schedule();

            return;
        }

        $commands = ($this->password === '' ? '' : self::command('AUTH', $this->password))
            . self::command('PSUBSCRIBE', $this->pattern);

        fwrite($socket, $commands);
        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->buffer = '';
    }

    public function read(): array
    {
        if ($this->socket === null) {
            return [];
        }

        $chunk = fread($this->socket, 65536);

        if (($chunk === false || $chunk === '') && feof($this->socket)) {
            $this->lost("Lost the Redis subscription; reconnecting in {$this->backoff}s.");

            return [];
        }

        $this->buffer .= (string) $chunk;
        $envelopes = [];

        try {
            while (($reply = $this->next()) !== null) {
                $envelope = $this->handle($reply);

                if ($envelope !== null) {
                    $envelopes[] = $envelope;
                }
            }
        } catch (RuntimeException $e) {
            $this->logger->error($e->getMessage());
            $this->lost("Lost the Redis subscription; reconnecting in {$this->backoff}s.");
        }

        if ($this->socket !== null && strlen($this->buffer) > self::BUFFER_LIMIT) {
            $this->lost('Redis sent a reply larger than the hub will hold; reconnecting.');
        }

        return $envelopes;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->subscribed = false;
    }

    /** @throws RuntimeException for an error reply */
    private function handle(mixed $reply): ?Envelope
    {
        if ($reply instanceof RespError) {
            throw new RuntimeException("Redis refused the subscription: {$reply->message}");
        }

        if (!is_array($reply) || !is_string($reply[0] ?? null)) {
            return null;
        }

        if ($reply[0] === 'psubscribe') {
            $this->subscribed = true;
            $this->resubscribed = true;
            $this->backoff = 1;

            return null;
        }

        if ($reply[0] !== 'pmessage' || !is_string($reply[2] ?? null) || !is_string($reply[3] ?? null)) {
            return null;
        }

        $envelope = Envelope::fromJson($reply[3]);
        $expected = substr($this->pattern, 0, -1) . ($envelope->topic ?? '');

        if ($envelope === null || $reply[2] !== $expected) {
            $this->logger->warning("Dropped a malformed broadcast on {$reply[2]}.");

            return null;
        }

        return $envelope;
    }

    /**
     * The next whole reply off the buffer, consuming it, or null when it has
     * not all arrived.
     *
     * @throws RuntimeException for bytes that are not RESP, or a reply too large to hold
     */
    private function next(): mixed
    {
        $offset = 0;
        $reply = $this->parse($offset);

        if ($reply === self::incomplete()) {
            return null;
        }

        $this->buffer = substr($this->buffer, $offset);

        return $reply;
    }

    private function parse(int &$offset): mixed
    {
        $end = strpos($this->buffer, "\r\n", $offset);

        if ($end === false) {
            return self::incomplete();
        }

        $type = $this->buffer[$offset];
        $line = substr($this->buffer, $offset + 1, $end - $offset - 1);
        $offset = $end + 2;

        switch ($type) {
            case '+':
                return $line;
            case '-':
                return new RespError($line);
            case ':':
                return (int) $line;
            case '$':
                $length = (int) $line;

                if ($length < 0) {
                    return null;
                }

                if ($length > self::BUFFER_LIMIT) {
                    throw new RuntimeException('Redis sent a reply larger than the hub will hold.');
                }

                if (strlen($this->buffer) < $offset + $length + 2) {
                    return self::incomplete();
                }

                $value = substr($this->buffer, $offset, $length);
                $offset += $length + 2;

                return $value;
            case '*':
                $items = [];

                for ($i = 0, $n = (int) $line; $i < $n; $i++) {
                    $item = $this->parse($offset);

                    if ($item === self::incomplete()) {
                        return self::incomplete();
                    }

                    $items[] = $item;
                }

                return $items;
            default:
                throw new RuntimeException('Redis sent something that is not RESP.');
        }
    }

    private function lost(string $message): void
    {
        $this->logger->warning($message);
        $this->close();
        $this->buffer = '';
        $this->schedule();
    }

    private function schedule(): void
    {
        $this->nextAttempt = $this->now() + $this->backoff;
        $this->backoff = min($this->backoff * 2, self::MAX_BACKOFF);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function incomplete(): object
    {
        return self::$incomplete ??= new \stdClass;
    }

    private static function command(string ...$args): string
    {
        $frame = '*' . count($args) . "\r\n";

        foreach ($args as $arg) {
            $frame .= '$' . strlen($arg) . "\r\n{$arg}\r\n";
        }

        return $frame;
    }
}
