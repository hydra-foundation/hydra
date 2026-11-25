<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub\Poll;

use Hydra\Broadcast\Hub\Poll\IoPoller;
use Hydra\Broadcast\Hub\Poll\Poller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhp;

#[CoversClass(IoPoller::class)]
#[RequiresPhp('>=8.6')]
final class IoPollerTest extends PollerContractTestCase
{
    protected function poller(): Poller
    {
        return new IoPoller;
    }

    public function test_it_names_the_kernel_backend(): void
    {
        // Linux CI and the Docker image: epoll. kqueue on a Mac, poll elsewhere.
        $this->assertContains((new IoPoller)->name(), ['epoll', 'kqueue', 'poll', 'eventports', 'wsapoll']);
    }

    public function test_asking_for_writes_twice_changes_nothing(): void
    {
        $poller = new IoPoller;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $poller->watch($pair[0]);

        $poller->wantWrite($pair[0], true);
        $poller->wantWrite($pair[0], true);

        $ready = $poller->wait(0.05);
        $this->assertNotNull($ready);
        $this->assertCount(1, $ready['write']);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function test_write_interest_for_a_socket_never_watched_is_ignored(): void
    {
        $poller = new IoPoller;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $poller->wantWrite($pair[0], true);

        $this->assertSame([], $poller->wait(0.01)['write'] ?? null);

        fclose($pair[0]);
        fclose($pair[1]);
    }
}
