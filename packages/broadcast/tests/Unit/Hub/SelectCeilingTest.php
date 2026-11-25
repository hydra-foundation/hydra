<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Poll\DescriptorCeilingException;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use Hydra\Broadcast\Hub\Server;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * select() fails outright once any socket it is handed is numbered 1024 or
 * higher. The cap keeps the hub below that; this is what happens if it gets
 * there anyway: the newest streams go, the rest keep being served.
 */
#[CoversClass(Server::class)]
#[CoversClass(SelectPoller::class)]
#[CoversClass(DescriptorCeilingException::class)]
final class SelectCeilingTest extends TestCase
{
    use HubHarness;

    /** @var list<resource> descriptors held only to push the next ones past 1023 */
    private array $padding = [];

    #[After]
    protected function unpad(): void
    {
        $this->release();
    }

    public function test_past_the_ceiling_the_newest_streams_are_closed_and_the_others_still_hear(): void
    {
        $early = $this->listener();
        $this->pad();

        $late = $this->browser(self::get($this->tokens->mint(2, ['demo'], 3600)));
        $later = $this->browser(self::get($this->tokens->mint(3, ['demo'], 3600)));

        $this->assertTrue($this->closedByServer($late), 'the streams past the ceiling are closed');
        $this->assertTrue($this->closedByServer($later));
        $this->assertSame(1, $this->server->connections(), 'the stream below the ceiling is kept');

        $this->publish(new Envelope('demo', 'tick', ['n' => 1], 1));
        $this->assertStringContainsString('"event":"tick"', $this->drain($early));
    }

    public function test_the_log_says_so_once_a_minute_and_what_to_do(): void
    {
        $this->listener();
        $this->pad();

        // Two past the ceiling within the minute: one line.
        $this->closedByServer($this->browser(self::get($this->tokens->mint(2, ['demo'], 3600))));
        $this->clock->advance('+59 seconds');
        $this->closedByServer($this->browser(self::get($this->tokens->mint(3, ['demo'], 3600))));
        $this->assertCount(1, $this->errors());

        // A minute on, the next one is said again.
        $this->clock->advance('+1 second');
        $this->closedByServer($this->browser(self::get($this->tokens->mint(4, ['demo'], 3600))));

        $errors = $this->errors();
        $this->assertCount(2, $errors);
        $this->assertSame(
            'stream_select cannot watch a descriptor numbered 1024 or higher. Closing the newest streams to keep serving the rest. '
            . 'Lower SSE_MAX_CONNECTIONS, or run the hub on PHP 8.6, which has no such ceiling.',
            $errors[0]['message'],
        );
    }

    public function test_once_descriptors_free_up_new_streams_are_served_again(): void
    {
        $this->listener();
        $this->pad();
        $this->closedByServer($this->browser(self::get($this->tokens->mint(2, ['demo'], 3600))));

        $this->release();
        $again = $this->listener(user: 3);

        $this->publish(new Envelope('demo', 'tick', ['n' => 2], 1));
        $this->assertStringContainsString('"event":"tick"', $this->drain($again));
    }

    public function test_with_no_stream_left_to_close_redis_is_dropped_to_come_back_low(): void
    {
        $this->server->stop();
        $this->subscriber = $subscriber = new HighSubscriber;
        $this->server = $this->makeServer(new HubConfig(host: '127.0.0.1', port: 1));

        $this->pad();
        $subscriber->reconnectHigh();
        $this->rounds();

        $this->assertTrue($subscriber->dropped, 'the subscription was closed so it reconnects');
        $this->assertCount(1, $this->errors());
    }

    public function test_the_poller_tells_the_ceiling_from_a_signal(): void
    {
        $poller = new SelectPoller;
        $this->pad();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $this->padding = [...$this->padding, ...$pair];
        $poller->watch($pair[0]);

        $this->expectException(DescriptorCeilingException::class);

        $poller->wait(0.01);
    }

    public function test_after_the_ceiling_a_signal_is_still_a_signal(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl is needed to send the process a signal.');
        }

        $poller = new SelectPoller;
        $this->pad();
        $high = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($high);
        $poller->watch($high[0]);

        try {
            $poller->wait(0.01);
            $this->fail('select should have refused the high descriptor');
        } catch (DescriptorCeilingException) {
        }

        // PHP's last error still says FD_SETSIZE; a signal now is not the ceiling.
        $poller->forget($high[0]);
        fclose($high[0]);
        fclose($high[1]);
        $this->release();
        $low = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($low);
        $this->padding = $low;
        $poller->watch($low[0]);

        $async = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {});
        pcntl_alarm(1);

        try {
            $ready = $poller->wait(5.0);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($async);
        }

        $this->assertNull($ready);
    }

    /** @return list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private function errors(): array
    {
        return array_values(array_filter($this->logger->records(), static fn (array $r): bool => $r['level'] === 'error'));
    }

    /** Opens descriptors until the next one handed out is numbered past 1023. */
    private function pad(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('Counting open descriptors needs /proc.');
        }

        // Room past 1024 for the padding and for PHPUnit to go on opening files.
        $limit = function_exists('posix_getrlimit') ? (posix_getrlimit()['soft openfiles'] ?? 0) : 0;

        if ($limit !== 'unlimited' && (int) $limit < 2048) {
            $this->markTestSkipped("The open-file limit ({$limit}) is too low to reach descriptor 1024 safely; run with ulimit -n 2048 or more.");
        }

        while (count((array) scandir('/proc/self/fd')) - 2 < 1040) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            array_push($this->padding, ...$pair);
        }
    }

    private function release(): void
    {
        foreach ($this->padding as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $this->padding = [];
    }
}
