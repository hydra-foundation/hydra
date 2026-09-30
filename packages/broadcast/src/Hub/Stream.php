<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Hydra\Broadcast\StreamGrant;

/**
 * One browser connection: first a request head being read, then either an
 * open event stream or a refusal on its way out before the close.
 *
 * @internal
 */
final class Stream
{
    public const READING = 'reading';
    public const STREAMING = 'streaming';
    public const CLOSING = 'closing';

    public string $state = self::READING;
    public string $head = '';
    public string $outbox = '';
    public ?StreamGrant $grant = null;
    public int $lastWrite;

    /** @param resource $socket */
    public function __construct(
        public readonly mixed $socket,
        public readonly int $openedAt,
    ) {
        $this->lastWrite = $openedAt;
    }

    public function isStreaming(): bool
    {
        return $this->state === self::STREAMING;
    }
}
