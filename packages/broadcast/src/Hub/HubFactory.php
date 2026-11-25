<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Closure;
use Hydra\Broadcast\Hub\Poll\IoPoller;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use Hydra\Broadcast\StreamToken;
use Io\Poll\Context;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Builds a hub on a socket that is already listening, so binding stays the command's to report. */
final readonly class HubFactory
{
    /** @var Closure(): Poller */
    private Closure $poller;

    /**
     * @param Closure(): Subscriber $subscriber
     * @param (Closure(): Poller)|null $poller what the hub waits through; the best this PHP has unless given
     */
    public function __construct(
        private Closure $subscriber,
        private StreamToken $tokens,
        private HubConfig $config,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?HubStatus $status = null,
        ?Closure $poller = null,
    ) {
        $this->poller = $poller ?? self::bestPoller(...);
    }

    /** Io\Poll where PHP has it (8.6), with no ceiling of its own; stream_select below that. */
    public static function bestPoller(): Poller
    {
        return class_exists(Context::class) ? new IoPoller : new SelectPoller;
    }

    /**
     * The settings with the stream cap settled for the poller this factory
     * builds with, for the command to check before it binds a port.
     *
     * @throws HubCapacityException
     */
    public function settled(): HubConfig
    {
        return $this->config->for(($this->poller)());
    }

    /** @param resource $listener */
    public function server(mixed $listener): Server
    {
        return new Server($listener, ($this->subscriber)(), $this->tokens, $this->config, $this->clock, $this->logger, $this->status, ($this->poller)());
    }
}
