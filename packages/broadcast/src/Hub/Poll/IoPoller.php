<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub\Poll;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;
use StreamPollHandle;
use Time\Duration;

/**
 * PHP 8.6's Io\Poll: epoll on Linux, kqueue on a Mac.
 *
 * The kernel keeps the watch list, so a round costs what is ready, not what
 * is open, and there is no FD_SETSIZE: the hub's only ceiling is the
 * process's open-file limit. Level-triggered, as the Poller contract says.
 *
 * @internal
 */
final class IoPoller implements Poller
{
    private readonly Context $context;

    /** @var array<int, Watcher> keyed by socket id */
    private array $watchers = [];

    /** @var array<int, bool> whether each watcher also asks for writes */
    private array $writing = [];

    public function __construct()
    {
        $this->context = new Context;
    }

    public function watch(mixed $socket): void
    {
        if (isset($this->watchers[(int) $socket])) {
            return;
        }

        $this->watchers[(int) $socket] = $this->context->add(new StreamPollHandle($socket), [Event::Read], $socket);
    }

    public function forget(mixed $socket): void
    {
        $watcher = $this->watchers[(int) $socket] ?? null;
        unset($this->watchers[(int) $socket], $this->writing[(int) $socket]);

        // Closed already, the watcher is inactive: the kernel dropped the
        // descriptor from its watch list with the close.
        if ($watcher?->isActive() === true) {
            $watcher->remove();
        }
    }

    public function wantWrite(mixed $socket, bool $want): void
    {
        $id = (int) $socket;

        // The hub says this after every write; only a change reaches the kernel.
        if (!isset($this->watchers[$id]) || ($this->writing[$id] ?? false) === $want) {
            return;
        }

        $this->watchers[$id]->modifyEvents($want ? [Event::Read, Event::Write] : [Event::Read]);
        $this->writing[$id] = $want;
    }

    public function wait(float $timeout): ?array
    {
        try {
            $triggered = $this->context->wait(Duration::fromMicroseconds((int) round($timeout * 1_000_000)));
        } catch (FailedPollWaitException) {
            // A signal interrupts the wait, which is not an error: the round ends early.
            return null;
        }

        $read = [];
        $write = [];

        foreach ($triggered as $watcher) {
            /** @var resource $socket */
            $socket = $watcher->getData();

            // A hang-up or an error is read too: reading is how the hub finds
            // the end of the stream and closes it.
            if ($watcher->hasTriggered(Event::Read) || $watcher->hasTriggered(Event::HangUp)
                || $watcher->hasTriggered(Event::ReadHangUp) || $watcher->hasTriggered(Event::Error)) {
                $read[] = $socket;
            }

            if ($watcher->hasTriggered(Event::Write)) {
                $write[] = $socket;
            }
        }

        return ['read' => $read, 'write' => $write];
    }

    public function name(): string
    {
        return strtolower($this->context->getBackend()->name);
    }

    public function capacity(): ?int
    {
        return null;
    }
}
