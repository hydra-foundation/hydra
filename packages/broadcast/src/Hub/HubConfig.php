<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * The hub's settings, validated at construction so `sse:serve` fails at start
 * rather than on the first browser.
 *
 * Redis connection settings are not here: the hub uses REDIS_*, the same
 * server the publisher does, and the channel prefix and status key follow
 * REDIS_PREFIX.
 */
final readonly class HubConfig
{
    /** A browser's request head, at most. */
    public const HEAD_LIMIT = 8192;

    /** Seconds a browser has to send its request head. */
    public const HEAD_TIMEOUT = 5;

    /** Bytes a stream may fall behind before it is dropped. */
    public const BACKLOG_LIMIT = 262144;

    /** Seconds between status writes; the key lives three times as long. */
    public const STATUS_EVERY = 10;

    /** Open streams when SSE_MAX_CONNECTIONS is unset, if the poller can watch that many. */
    public const MAX_CONNECTIONS = 1000;

    /**
     * @param int $tokenTtl seconds a listen token lasts. Expiry is the only
     *        revocation, so this is the longest an open stream outlives a
     *        sign-out or a lost ability; fifteen minutes, not an hour, since
     *        a fresh token costs the page one request.
     */
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public ?int $maxConnections = null,
        public int $heartbeat = 15,
        public int $tokenTtl = 900,
        public string $channelPrefix = 'broadcast.',
        public string $statusKey = 'sse:hub',
    ) {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException("SSE_LISTEN must be host:port; got \"{$host}:{$port}\".");
        }

        foreach (['SSE_MAX_CONNECTIONS' => $maxConnections, 'SSE_HEARTBEAT' => $heartbeat, 'STREAM_TOKEN_TTL' => $tokenTtl] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("{$name} must be at least 1; got {$value}.");
            }
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        $listen = $env->string('SSE_LISTEN', '0.0.0.0:8080');
        $at = strrpos($listen, ':');

        if ($at === false || !ctype_digit(substr($listen, $at + 1))) {
            throw new InvalidArgumentException("SSE_LISTEN must be host:port; got \"{$listen}\".");
        }

        $prefix = $env->string('REDIS_PREFIX', '');

        return new self(
            host: substr($listen, 0, $at),
            port: (int) substr($listen, $at + 1),
            maxConnections: $env->has('SSE_MAX_CONNECTIONS') ? $env->int('SSE_MAX_CONNECTIONS') : null,
            heartbeat: $env->int('SSE_HEARTBEAT', 15),
            tokenTtl: $env->int('STREAM_TOKEN_TTL', 900),
            channelPrefix: $prefix . 'broadcast.',
            statusKey: $prefix . 'sse:hub',
        );
    }

    /**
     * These settings with the stream cap settled for $poller: unset, a
     * thousand or what the poller can watch if that is fewer; set, kept, or
     * refused when the poller cannot watch that many.
     *
     * @throws HubCapacityException
     */
    public function for(Poller $poller): self
    {
        $capacity = $poller->capacity();

        if ($this->maxConnections === null) {
            $max = min(self::MAX_CONNECTIONS, $capacity ?? self::MAX_CONNECTIONS);
        } elseif ($capacity !== null && $this->maxConnections > $capacity) {
            throw new HubCapacityException(
                "SSE_MAX_CONNECTIONS={$this->maxConnections} is more than this PHP can watch with stream_select ({$capacity}). "
                . "Lower it to {$capacity} or less, or run the hub on PHP 8.6, which waits through Io\\Poll and has no such limit.",
            );
        } else {
            $max = $this->maxConnections;
        }

        return new self($this->host, $this->port, $max, $this->heartbeat, $this->tokenTtl, $this->channelPrefix, $this->statusKey);
    }
}
