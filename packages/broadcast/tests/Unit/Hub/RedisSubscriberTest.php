<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\RedisSubscriber;
use Hydra\Cache\CacheConfig;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The hub's ear on Redis, against a scripted server: the far end of a socket
 * pair, which the test reads commands from and writes replies to.
 */
#[CoversClass(RedisSubscriber::class)]
final class RedisSubscriberTest extends TestCase
{
    private const PATTERN = 'app:broadcast.*';

    private FrozenClock $clock;
    private CapturingLogger $logger;

    /** @var list<resource> the server ends, one per connection made */
    private array $servers = [];

    /** @var list<resource> */
    private array $open = [];

    private int $attempts = 0;
    private bool $refuse = false;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-30 12:00:00 UTC');
        $this->logger = new CapturingLogger;
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function test_it_subscribes_on_first_maintain_without_auth_when_there_is_no_password(): void
    {
        $subscriber = $this->subscriber();
        $this->assertNull($subscriber->socket());

        $subscriber->maintain();

        $this->assertNotNull($subscriber->socket());
        $this->assertSame("*2\r\n\$10\r\nPSUBSCRIBE\r\n\$15\r\n" . self::PATTERN . "\r\n", $this->received(0));
        $this->assertFalse($subscriber->subscribed());
    }

    public function test_it_authenticates_first_when_there_is_a_password(): void
    {
        $subscriber = $this->subscriber('s3cret');
        $subscriber->maintain();

        $this->assertSame(
            "*2\r\n\$4\r\nAUTH\r\n\$6\r\ns3cret\r\n*2\r\n\$10\r\nPSUBSCRIBE\r\n\$15\r\n" . self::PATTERN . "\r\n",
            $this->received(0),
        );
    }

    public function test_an_accepted_password_then_the_confirmation_subscribes(): void
    {
        $subscriber = $this->subscriber('s3cret');
        $subscriber->maintain();
        $this->reply(0, "+OK\r\n*3\r\n\$10\r\npsubscribe\r\n\$15\r\n" . self::PATTERN . "\r\n:1\r\n");

        $this->assertSame([], $subscriber->read());
        $this->assertTrue($subscriber->subscribed());
    }

    public function test_the_confirmation_marks_it_subscribed_and_resubscribed_once(): void
    {
        $subscriber = $this->connected();

        $this->assertTrue($subscriber->subscribed());
        $this->assertTrue($subscriber->resubscribed());
        $this->assertFalse($subscriber->resubscribed());
    }

    public function test_a_message_becomes_an_envelope_even_fed_a_byte_at_a_time(): void
    {
        $subscriber = $this->connected();
        $envelope = new Envelope('module.users', 'changed', ['id' => 7], 5);
        $frame = self::pmessage('app:broadcast.module.users', $envelope->toJson());

        $got = [];
        foreach (str_split($frame) as $byte) {
            fwrite($this->servers[0], $byte);
            array_push($got, ...$subscriber->read());
        }

        $this->assertEquals([$envelope], $got);
    }

    public function test_several_messages_in_one_read_come_out_in_order(): void
    {
        $subscriber = $this->connected();
        $a = new Envelope('a', 'changed', [], 1);
        $b = new Envelope('b', 'changed', [], 2);

        fwrite($this->servers[0], self::pmessage('app:broadcast.a', $a->toJson()) . self::pmessage('app:broadcast.b', $b->toJson()));

        $this->assertEquals([$a, $b], $subscriber->read());
    }

    public function test_a_malformed_envelope_is_dropped_and_logged(): void
    {
        $subscriber = $this->connected();
        fwrite($this->servers[0], self::pmessage('app:broadcast.x', '{nope'));

        $this->assertSame([], $subscriber->read());
        $this->assertSame(['Dropped a malformed broadcast on app:broadcast.x.'], $this->logger->messages());
    }

    public function test_an_envelope_on_another_topics_channel_is_dropped(): void
    {
        // The channel names the topic; an envelope claiming another one was
        // not written by the publisher.
        $subscriber = $this->connected();
        fwrite($this->servers[0], self::pmessage('app:broadcast.demo', (new Envelope('user.1', 'x', [], 1))->toJson()));

        $this->assertSame([], $subscriber->read());
        $this->assertSame(['Dropped a malformed broadcast on app:broadcast.demo.'], $this->logger->messages());
    }

    public function test_an_error_reply_is_logged_and_the_connection_dropped(): void
    {
        $subscriber = $this->subscriber('wrong');
        $subscriber->maintain();
        fwrite($this->servers[0], "-WRONGPASS invalid username-password pair\r\n");

        $subscriber->read();

        $this->assertNull($subscriber->socket());
        $this->assertFalse($subscriber->subscribed());
        $this->assertContains('Redis refused the subscription: WRONGPASS invalid username-password pair', $this->logger->messages());
    }

    public function test_a_lost_connection_reconnects_after_a_growing_backoff(): void
    {
        $subscriber = $this->connected();
        fclose($this->servers[0]);

        $this->assertSame([], $subscriber->read());
        $this->assertNull($subscriber->socket());
        $this->assertFalse($subscriber->subscribed());
        $this->assertSame(['Lost the Redis subscription; reconnecting in 1s.'], $this->logger->messages());

        $this->refuse = true;
        $subscriber->maintain();
        $this->assertSame(1, $this->attempts, 'Not before the backoff has passed.');

        foreach ([1, 2, 4, 8, 16, 30, 30] as $wait) {
            $this->clock->advance("+{$wait} seconds");
            $before = $this->attempts;
            $subscriber->maintain();
            $this->assertSame($before + 1, $this->attempts, "An attempt after {$wait}s.");
        }

        $this->refuse = false;
        $this->clock->advance('+30 seconds');
        $subscriber->maintain();
        $this->reply(count($this->servers) - 1, "*3\r\n\$10\r\npsubscribe\r\n\$15\r\n" . self::PATTERN . "\r\n:1\r\n");
        $subscriber->read();

        $this->assertTrue($subscriber->subscribed());
        $this->assertTrue($subscriber->resubscribed());
    }

    public function test_a_confirmed_subscription_resets_the_backoff(): void
    {
        $subscriber = $this->connected();
        fclose($this->servers[0]);
        $subscriber->read();

        $this->clock->advance('+1 second');
        $subscriber->maintain();
        $this->reply(1, "*3\r\n\$10\r\npsubscribe\r\n\$15\r\n" . self::PATTERN . "\r\n:1\r\n");
        $subscriber->read();
        fclose($this->servers[1]);
        $subscriber->read();

        $this->assertSame('Lost the Redis subscription; reconnecting in 1s.', $this->logger->messages()[1]);
    }

    public function test_a_failed_connect_is_logged_and_retried(): void
    {
        $this->refuse = true;
        $subscriber = $this->subscriber();

        $subscriber->maintain();

        $this->assertNull($subscriber->socket());
        $this->assertSame(['Could not subscribe to Redis: refused; retrying in 1s.'], $this->logger->messages());
    }

    public function test_an_oversized_reply_drops_the_connection(): void
    {
        $subscriber = $this->connected();
        fwrite($this->servers[0], '$' . (RedisSubscriber::BUFFER_LIMIT + 1) . "\r\n");
        for ($i = 0; $i < 20 && $subscriber->socket() !== null; $i++) {
            @fwrite($this->servers[0], str_repeat('x', 65536));
            $subscriber->read();
        }

        $this->assertNull($subscriber->socket());
    }

    public function test_replies_it_has_no_use_for_are_skipped(): void
    {
        $subscriber = $this->connected();
        $envelope = new Envelope('demo', 'changed', [], 1);

        fwrite($this->servers[0], "\$-1\r\n*1\r\n\$4\r\npong\r\n:5\r\n" . self::pmessage('app:broadcast.demo', $envelope->toJson()));

        $this->assertEquals([$envelope], $subscriber->read());
        $this->assertSame([], $this->logger->messages());
    }

    public function test_bytes_that_are_not_resp_drop_the_connection(): void
    {
        $subscriber = $this->connected();
        fwrite($this->servers[0], "!what\r\n");

        $subscriber->read();

        $this->assertNull($subscriber->socket());
        $this->assertSame(['Redis sent something that is not RESP.', 'Lost the Redis subscription; reconnecting in 1s.'], $this->logger->messages());
    }

    public function test_reading_while_disconnected_is_nothing(): void
    {
        $this->assertSame([], $this->subscriber()->read());
    }

    public function test_the_cache_settings_name_the_server_and_a_dead_one_is_retried(): void
    {
        $subscriber = RedisSubscriber::over(
            new CacheConfig(host: '127.0.0.1', port: 1, timeout: 0.2),
            self::PATTERN,
            $this->clock,
            $this->logger,
        );

        $subscriber->maintain();

        $this->assertNull($subscriber->socket());
        $this->assertStringStartsWith('Could not subscribe to Redis: Could not connect to Redis at 127.0.0.1:1: ', $this->logger->messages()[0]);
        $this->assertStringContainsString('check REDIS_HOST and REDIS_PORT', $this->logger->messages()[0]);
    }

    public function test_close_drops_the_socket(): void
    {
        $subscriber = $this->connected();
        $subscriber->close();

        $this->assertNull($subscriber->socket());
        $this->assertFalse($subscriber->subscribed());
    }

    private function subscriber(string $password = ''): RedisSubscriber
    {
        return new RedisSubscriber(
            function () {
                $this->attempts++;

                if ($this->refuse) {
                    throw new RuntimeException('refused');
                }

                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $this->assertIsArray($pair);
                [$client, $server] = $pair;
                stream_set_blocking($server, false);
                $this->servers[] = $server;
                array_push($this->open, $client, $server);

                return $client;
            },
            self::PATTERN,
            $password,
            $this->clock,
            $this->logger,
        );
    }

    private function connected(): RedisSubscriber
    {
        $subscriber = $this->subscriber();
        $subscriber->maintain();
        $this->reply(0, "*3\r\n\$10\r\npsubscribe\r\n\$15\r\n" . self::PATTERN . "\r\n:1\r\n");
        $subscriber->read();

        return $subscriber;
    }

    private function reply(int $server, string $bytes): void
    {
        fwrite($this->servers[$server], $bytes);
    }

    private function received(int $server): string
    {
        return (string) stream_get_contents($this->servers[$server]);
    }

    private static function pmessage(string $channel, string $payload): string
    {
        $parts = ['pmessage', self::PATTERN, $channel, $payload];

        return '*4' . "\r\n" . implode('', array_map(static fn (string $p): string => '$' . strlen($p) . "\r\n{$p}\r\n", $parts));
    }
}
