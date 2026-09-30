<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Closure;
use Hydra\Broadcast\StreamToken;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Builds a hub on a socket that is already listening, so binding stays the command's to report. */
final readonly class HubFactory
{
    /** @param Closure(): Subscriber $subscriber */
    public function __construct(
        private Closure $subscriber,
        private StreamToken $tokens,
        private HubConfig $config,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?HubStatus $status = null,
    ) {}

    /** @param resource $listener */
    public function server(mixed $listener): Server
    {
        return new Server($listener, ($this->subscriber)(), $this->tokens, $this->config, $this->clock, $this->logger, $this->status);
    }
}
