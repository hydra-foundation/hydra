<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

/** A subscription whose socket came back on a descriptor past the ceiling. */
final class HighSubscriber extends HandFedSubscriber
{
    public bool $dropped = false;

    /** @var resource|null */
    private mixed $high = null;

    /** @var resource|null */
    private mixed $peer = null;

    public function reconnectHigh(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair !== false) {
            [$this->high, $this->peer] = $pair;
        }
    }

    public function socket()
    {
        return is_resource($this->high) ? $this->high : parent::socket();
    }

    public function close(): void
    {
        if (is_resource($this->high)) {
            $this->dropped = true;
            fclose($this->high);
            $this->high = null;
        }

        if (is_resource($this->peer)) {
            fclose($this->peer);
        }

        parent::close();
    }
}
