<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub\Poll;

use Hydra\Broadcast\Hub\Poll\Poller;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the hub needs from whatever it waits on, whichever poller that is.
 * Level-triggered: a socket with bytes still unread is reported again.
 */
abstract class PollerContractTestCase extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    abstract protected function poller(): Poller;

    #[After]
    protected function closeAll(): void
    {
        foreach ($this->open as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function test_a_watched_socket_with_bytes_waiting_is_reported_readable(): void
    {
        $poller = $this->poller();
        [$near, $far] = $this->pair();
        $poller->watch($near);

        fwrite($far, 'x');

        $this->assertSame([(int) $near], $this->ids($poller->wait(0.05)['read']));
    }

    public function test_a_quiet_socket_is_not_reported(): void
    {
        $poller = $this->poller();
        [$near] = $this->pair();
        $poller->watch($near);

        $ready = $poller->wait(0.01);

        $this->assertSame([], $ready['read']);
        $this->assertSame([], $ready['write']);
    }

    public function test_bytes_left_unread_are_reported_again_next_round(): void
    {
        $poller = $this->poller();
        [$near, $far] = $this->pair();
        $poller->watch($near);
        fwrite($far, 'x');

        $poller->wait(0.05);

        $this->assertSame([(int) $near], $this->ids($poller->wait(0.05)['read']));
    }

    public function test_write_interest_is_reported_only_while_wanted(): void
    {
        $poller = $this->poller();
        [$near] = $this->pair();
        $poller->watch($near);

        $poller->wantWrite($near, true);
        $this->assertSame([(int) $near], $this->ids($poller->wait(0.05)['write']));

        $poller->wantWrite($near, false);
        $this->assertSame([], $poller->wait(0.01)['write']);
    }

    public function test_a_forgotten_socket_is_never_reported(): void
    {
        $poller = $this->poller();
        [$near, $far] = $this->pair();
        $poller->watch($near);
        $poller->wantWrite($near, true);

        $poller->forget($near);
        fwrite($far, 'x');

        $ready = $poller->wait(0.01);
        $this->assertSame([], $ready['read']);
        $this->assertSame([], $ready['write']);
    }

    public function test_forgetting_a_socket_already_closed_is_harmless(): void
    {
        $poller = $this->poller();
        [$near] = $this->pair();
        $poller->watch($near);
        fclose($near);

        $poller->forget($near);

        $this->assertSame([], $poller->wait(0.01)['read']);
    }

    public function test_a_hang_up_is_reported_readable_so_the_hub_sees_the_end(): void
    {
        $poller = $this->poller();
        [$near, $far] = $this->pair();
        $poller->watch($near);

        fclose($far);

        $this->assertSame([(int) $near], $this->ids($poller->wait(0.05)['read']));
        $this->assertSame('', fread($near, 10));
        $this->assertTrue(feof($near));
    }

    public function test_watching_twice_is_one_watch(): void
    {
        $poller = $this->poller();
        [$near, $far] = $this->pair();
        $poller->watch($near);
        $poller->watch($near);
        fwrite($far, 'x');

        $this->assertSame([(int) $near], $this->ids($poller->wait(0.05)['read']));
    }

    public function test_with_nothing_watched_the_wait_still_times_out(): void
    {
        $started = microtime(true);

        $ready = $this->poller()->wait(0.02);

        $this->assertSame([], $ready['read']);
        $this->assertGreaterThanOrEqual(0.015, microtime(true) - $started);
    }

    public function test_a_signal_during_the_wait_ends_it_early_with_null(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl is needed to send the process a signal.');
        }

        $poller = $this->poller();
        [$near] = $this->pair();
        $poller->watch($near);

        $async = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {});
        pcntl_alarm(1);

        try {
            $started = microtime(true);
            $ready = $poller->wait(5.0);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($async);
        }

        $this->assertNull($ready);
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function test_it_says_what_it_is(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z]+$/', $this->poller()->name());
    }

    /** @return array{resource, resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('No socket pair.');
        }

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        array_push($this->open, ...$pair);

        return $pair;
    }

    /**
     * @param list<resource> $sockets
     * @return list<int>
     */
    private function ids(array $sockets): array
    {
        return array_map(static fn ($s): int => (int) $s, $sockets);
    }
}
