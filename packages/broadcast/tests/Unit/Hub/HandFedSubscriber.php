<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\Subscriber;
use RuntimeException;

/** A subscriber the test hands envelopes to; a socket pair wakes the loop. */
class HandFedSubscriber implements Subscriber
{
    /** @var list<Envelope> */
    private array $queue = [];

    /** @var array{resource, resource} */
    private array $pair;

    private bool $subscribed = true;
    private bool $resubscribed = false;

    public function __construct()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('No socket pair.');
        }

        stream_set_blocking($pair[0], false);
        $this->pair = $pair;
    }

    public function push(Envelope $envelope): void
    {
        $this->queue[] = $envelope;
        fwrite($this->pair[1], '.');
    }

    public function comeBack(): void
    {
        $this->subscribed = true;
        $this->resubscribed = true;
        fwrite($this->pair[1], '.');
    }

    public function goAway(): void
    {
        $this->subscribed = false;
    }

    public function socket()
    {
        return is_resource($this->pair[0]) ? $this->pair[0] : null;
    }

    public function read(): array
    {
        fread($this->pair[0], 65536);
        $queue = $this->queue;
        $this->queue = [];

        return $queue;
    }

    /** @var (callable(): void)|null runs on every maintain(), which the server calls each round */
    public $onMaintain = null;

    public function maintain(): void
    {
        if ($this->onMaintain !== null) {
            ($this->onMaintain)();
        }
    }

    public function resubscribed(): bool
    {
        $was = $this->resubscribed;
        $this->resubscribed = false;

        return $was;
    }

    public function subscribed(): bool
    {
        return $this->subscribed;
    }

    public function close(): void
    {
        foreach ($this->pair as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
