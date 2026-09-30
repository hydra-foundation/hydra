<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\Drivers\Channel;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The redis driver against a stand-in channel, so every branch runs without a
 * server. The wire format against a real one is RedisBroadcastingTest's.
 */
#[CoversClass(RedisBroadcaster::class)]
final class RedisBroadcasterTest extends TestCase
{
    private CapturingLogger $logger;

    /** @var list<array{string, string}> */
    private array $sent = [];

    private int $opened = 0;

    protected function setUp(): void
    {
        $this->logger = new CapturingLogger;
    }

    public function test_it_publishes_the_envelope_on_the_topic_channel_timed_by_the_clock(): void
    {
        $this->broadcaster()->publish('module.users', 'changed', ['id' => 7]);

        $this->assertSame(
            [['hydra:broadcast.module.users', '{"topic":"module.users","event":"changed","data":{"id":7},"at":1759230000123}']],
            $this->sent,
        );
        $this->assertSame([], $this->logger->records());
    }

    public function test_nothing_connects_until_the_first_publish_and_then_once(): void
    {
        $broadcaster = $this->broadcaster();
        $this->assertSame(0, $this->opened);

        $broadcaster->publish('a', 'changed');
        $broadcaster->publish('b', 'changed');

        $this->assertSame(1, $this->opened);
        $this->assertCount(2, $this->sent);
    }

    public function test_a_bad_publish_throws_before_anything_connects(): void
    {
        try {
            $this->broadcaster()->publish('module users', 'changed');
            $this->fail('A bad topic must throw.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, $this->opened);
    }

    public function test_an_oversized_payload_throws_rather_than_being_logged(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->broadcaster()->publish('users', 'changed', ['p' => str_repeat('x', 70_000)]);
    }

    public function test_a_connection_that_cannot_open_is_logged_and_retried_on_the_next_publish(): void
    {
        $broadcaster = $this->broadcaster(fn () => throw new RuntimeException('Could not connect to Redis at redis:6379.'));

        $broadcaster->publish('users', 'changed');
        $broadcaster->publish('users', 'changed');

        $this->assertSame(2, $this->opened);
        $this->assertSame(
            ['Could not broadcast changed on users: Could not connect to Redis at redis:6379.'],
            array_unique($this->logger->messages()),
        );
        $this->assertSame('warning', $this->logger->records()[0]['level']);
        $this->assertSame(['topic' => 'users', 'event' => 'changed'], array_intersect_key(
            $this->logger->records()[0]['context'],
            ['topic' => 1, 'event' => 1],
        ));
    }

    public function test_a_failed_publish_drops_the_connection_so_the_next_one_reconnects(): void
    {
        $fail = true;
        $broadcaster = $this->broadcaster(null, function () use (&$fail): void {
            if ($fail) {
                $fail = false;

                throw new RuntimeException('Connection lost');
            }
        });

        $broadcaster->publish('users', 'changed');
        $broadcaster->publish('users', 'changed');

        $this->assertSame(2, $this->opened);
        $this->assertSame(['Could not broadcast changed on users: Connection lost'], $this->logger->messages());
        $this->assertCount(1, $this->sent);
    }

    public function test_a_failure_writes_no_output(): void
    {
        // A displayed warning sends the headers, so a failed nudge would turn
        // the response it rode on into a 200 with the wrong body.
        $this->expectOutputString('');

        $this->broadcaster(fn () => throw new RuntimeException('down'))->publish('users', 'changed');
    }

    /**
     * @param (callable(): never)|null $open replaces the opener
     * @param (callable(): void)|null $before runs before each send, and may throw
     */
    private function broadcaster(?callable $open = null, ?callable $before = null): RedisBroadcaster
    {
        $channel = new class (function (string $channel, string $message) use ($before): void {
            if ($before !== null) {
                $before();
            }

            $this->sent[] = [$channel, $message];
        }) implements Channel {
            /** @param Closure(string, string): void $send */
            public function __construct(private readonly Closure $send) {}

            public function publish(string $channel, string $message): void
            {
                ($this->send)($channel, $message);
            }
        };

        return new RedisBroadcaster(
            function () use ($open, $channel): Channel {
                $this->opened++;

                if ($open !== null) {
                    $open();
                }

                return $channel;
            },
            'hydra:broadcast.',
            $this->logger,
            new FrozenClock('2025-09-30 11:00:00.123 UTC'),
        );
    }
}
