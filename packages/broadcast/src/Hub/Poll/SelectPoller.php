<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub\Poll;

/**
 * stream_select(), for PHP without Io\Poll (below 8.6).
 *
 * select() hands the kernel every socket each round, and can only watch
 * descriptors numbered below FD_SETSIZE (1024): one socket past that and the
 * whole call fails.
 *
 * @internal
 */
final class SelectPoller implements Poller
{
    /** @var array<int, resource> keyed by socket id */
    private array $sockets = [];

    /** @var array<int, true> socket ids with bytes waiting to go out */
    private array $writers = [];

    public function watch(mixed $socket): void
    {
        $this->sockets[(int) $socket] = $socket;
    }

    public function forget(mixed $socket): void
    {
        unset($this->sockets[(int) $socket], $this->writers[(int) $socket]);
    }

    public function wantWrite(mixed $socket, bool $want): void
    {
        if ($want && isset($this->sockets[(int) $socket])) {
            $this->writers[(int) $socket] = true;
        } else {
            unset($this->writers[(int) $socket]);
        }
    }

    public function wait(float $timeout): ?array
    {
        // A socket closed by its owner without being forgotten (the Redis
        // connection dropping) would make stream_select throw.
        $this->sockets = array_filter($this->sockets, is_resource(...));
        $this->writers = array_intersect_key($this->writers, $this->sockets);

        $read = array_values($this->sockets);
        $write = array_values(array_intersect_key($this->sockets, $this->writers));
        $except = null;
        $seconds = (int) $timeout;
        $micros = (int) (($timeout - $seconds) * 1_000_000);

        if ($read === [] && $write === []) {
            usleep($seconds * 1_000_000 + $micros);

            return ['read' => [], 'write' => []];
        }

        // A signal interrupts the wait, which is not an error: the round ends early.
        if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
            return null;
        }

        // stream_select keeps the keys of the sockets it leaves in; spreading
        // renumbers them into the list the hub expects.
        return ['read' => [...$read], 'write' => [...$write]];
    }

    public function name(): string
    {
        return 'select';
    }
}
