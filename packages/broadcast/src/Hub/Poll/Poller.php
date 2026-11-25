<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub\Poll;

/**
 * What one round of the hub waits on: the listener, Redis and every browser.
 *
 * Sockets are watched for as long as they are open, not handed over each
 * round, so a poller that keeps state in the kernel (epoll) does no work per
 * socket per round. Level-triggered: a socket with bytes still unread, or
 * still able to take more, is reported again on the next wait.
 *
 * @internal
 */
interface Poller
{
    /**
     * Reports $socket when it has bytes to read, or has been hung up on.
     * Watching a socket twice is one watch.
     *
     * @param resource $socket
     */
    public function watch(mixed $socket): void;

    /**
     * Stops reporting $socket. Call it before fclose(); after is harmless too.
     *
     * @param resource $socket
     */
    public function forget(mixed $socket): void;

    /**
     * Whether to report $socket once it can take bytes: true while the hub
     * has some waiting for it, false once they are all written.
     *
     * @param resource $socket
     */
    public function wantWrite(mixed $socket, bool $want): void;

    /**
     * Waits up to $timeout seconds for any watched socket to be ready.
     * Null when a signal interrupted the wait, so the round ends early.
     *
     * @return array{read: list<resource>, write: list<resource>}|null
     */
    public function wait(float $timeout): ?array;

    /** What it waits with, for the log and System Health: "epoll", "select", … */
    public function name(): string;
}
