<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\Hub\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** What keeps the hub honest over hours: the cap, the heartbeat, expiry, slow browsers, Redis coming back, and stopping. */
#[CoversClass(Server::class)]
#[CoversClass(Stream::class)]
final class ServerLimitsTest extends TestCase
{
    use HubHarness;

    public function test_over_the_cap_a_browser_is_told_to_come_back(): void
    {
        $this->server->stop();
        $this->server = $this->makeServer(new HubConfig(host: '127.0.0.1', port: 1, maxConnections: 2));

        $this->listener();
        $this->listener();
        $third = $this->browser(self::get($this->tokens->mint(1, ['demo'], 60)));

        $this->assertStringStartsWith("HTTP/1.1 503 Service Unavailable\r\n", $got = $this->drain($third));
        $this->assertStringContainsString("Retry-After: 5\r\n", $got);
        $this->assertSame(2, $this->server->streams());
    }

    public function test_a_quiet_stream_gets_a_heartbeat_after_the_interval(): void
    {
        $browser = $this->listener();

        $this->clock->advance('+14 seconds');
        $this->assertSame('', $this->drain($browser));

        $this->clock->advance('+1 second');
        $this->assertSame(": ping\n\n", $this->drain($browser));

        $this->clock->advance('+1 second');
        $this->assertSame('', $this->drain($browser), 'Once per interval, not every round.');
    }

    public function test_an_event_counts_as_traffic_and_puts_the_heartbeat_off(): void
    {
        $browser = $this->listener();
        $this->clock->advance('+10 seconds');
        $this->publish(new Envelope('demo', 'changed', [], 1));
        $this->drain($browser);

        $this->clock->advance('+10 seconds');

        $this->assertSame('', $this->drain($browser));
    }

    public function test_a_stream_is_closed_when_its_token_expires(): void
    {
        $browser = $this->listener(['demo'], 60);

        $this->clock->advance('+60 seconds');
        $this->assertFalse($this->closedByServer($browser));

        $this->clock->advance('+1 second');
        $this->assertTrue($this->closedByServer($browser));
        $this->assertSame(0, $this->server->streams());
    }

    public function test_a_browser_that_stops_reading_is_dropped_and_the_others_still_hear(): void
    {
        $slow = $this->listener();
        $fast = $this->listener();
        $big = new Envelope('demo', 'changed', ['p' => str_repeat('x', 60_000)], 1);

        // The slow one never reads; the fast one reads after every publish.
        for ($i = 0; $i < 400 && $this->server->streams() === 2; $i++) {
            $this->publish($big);
            $this->drain($fast);
        }

        $this->assertSame(1, $this->server->streams(), 'The slow browser was dropped.');
        $this->assertContains('Dropped a stream more than 262144 bytes behind.', $this->logger->messages());

        $this->publish(new Envelope('demo', 'after', [], 2));
        $this->assertStringContainsString('"event":"after"', $this->drain($fast));
        $this->assertNotNull($slow);
    }

    public function test_a_backlog_drains_once_the_browser_reads_again(): void
    {
        $browser = $this->listener();
        $big = new Envelope('demo', 'changed', ['p' => str_repeat('x', 60_000)], 1);

        // Fill the socket until the hub has to hold some back, short of the limit.
        $sent = 0;
        for ($i = 0; $i < 400; $i++) {
            $this->publish($big);
            $sent++;

            if ($this->server->backlog() > 0) {
                break;
            }
        }
        $this->assertGreaterThan(0, $this->server->backlog());

        $got = '';
        for ($i = 0; $i < 400 && substr_count($got, "\n\n") < $sent; $i++) {
            $got .= $this->drain($browser);
        }

        $this->assertSame($sent, substr_count($got, 'event: demo'));
        $this->assertSame(0, $this->server->backlog());
        $this->assertSame(1, $this->server->streams());
    }

    public function test_past_the_cap_and_the_room_for_heads_a_connection_is_closed_at_once(): void
    {
        $this->server->stop();
        $this->server = $this->makeServer(new HubConfig(host: '127.0.0.1', port: 1, maxConnections: 1));

        $sockets = [];
        for ($i = 0; $i < 70; $i++) {
            $socket = stream_socket_client("tcp://{$this->address}", $errno, $error, 1.0);
            $this->assertNotFalse($socket);
            $this->browsers[] = $sockets[] = $socket;
            $this->server->tick(0.005);
        }
        $this->rounds();

        $this->assertSame(1 + 64, $this->server->connections());
    }

    public function test_bytes_after_the_head_are_ignored(): void
    {
        $browser = $this->listener();

        fwrite($browser, "more\r\n\r\n");
        $this->rounds();

        $this->assertSame(1, $this->server->streams());
        $this->assertSame('', $this->drain($browser));
    }

    public function test_every_stream_is_told_to_resync_when_redis_comes_back(): void
    {
        $a = $this->listener(['demo']);
        $b = $this->listener(['module.users']);

        $this->subscriber->goAway();
        $this->subscriber->comeBack();
        $this->rounds();

        $this->assertSame("event: hub.resync\ndata: {}\n\n", $this->drain($a));
        $this->assertSame("event: hub.resync\ndata: {}\n\n", $this->drain($b));
    }

    public function test_stop_closes_every_stream_and_the_listener(): void
    {
        $browser = $this->listener();

        $this->server->stop();

        $this->assertTrue(feof($browser) || fread($browser, 1) === '' && feof($browser));
        $this->assertSame(0, $this->server->connections());
        $this->assertFalse(@stream_socket_client("tcp://{$this->address}", $errno, $error, 0.2));
    }

    public function test_the_status_is_written_at_start_then_every_ten_seconds_and_cleared_on_stop(): void
    {
        $this->listener();
        $this->assertSame(1, $this->status->writes);

        $report = $this->status->read();
        $this->assertNotNull($report);
        $this->assertSame([getmypid(), 1790769600, true], [$report->pid, $report->startedAt, $report->subscribed]);
        $this->assertSame(30, $this->status->ttl);

        $this->clock->advance('+9 seconds');
        $this->rounds();
        $this->assertSame(1, $this->status->writes);

        $this->clock->advance('+1 second');
        $this->rounds();
        $this->assertSame(2, $this->status->writes);
        $this->assertSame(1, $this->status->read()?->connections);

        $this->subscriber->goAway();
        $this->clock->advance('+10 seconds');
        $this->rounds();
        $this->assertFalse($this->status->read()?->subscribed);

        $this->server->stop();
        $this->assertNull($this->status->read());
    }

    public function test_run_serves_until_halted_then_stops(): void
    {
        $browser = $this->listener();
        $rounds = 0;
        $this->subscriber->onMaintain = function () use (&$rounds): void {
            if (++$rounds === 3) {
                $this->server->halt();
            }
        };

        $this->server->run();

        $this->assertSame(3, $rounds);
        $this->assertSame(0, $this->server->connections());
        $this->assertNull($this->status->read());
        $this->assertTrue($this->closedByServer($browser));
    }

    public function test_run_and_tick_do_nothing_once_stopped(): void
    {
        $this->server->stop();
        $this->server->run();
        $this->server->tick(0.01);

        $this->assertSame(0, $this->server->connections());
    }
}
