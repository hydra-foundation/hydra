<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\Drivers\LogBroadcaster;
use Hydra\Broadcast\Drivers\NullBroadcaster;
use Hydra\Log\Testing\CapturingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two drivers that reach no broker. Both still validate: a mistake the
 * null driver let through in development would only surface under Redis in
 * production.
 */
#[CoversClass(LogBroadcaster::class)]
#[CoversClass(NullBroadcaster::class)]
final class DriversTest extends TestCase
{
    public function test_the_log_driver_writes_one_info_line_with_the_data(): void
    {
        $logger = new CapturingLogger;

        (new LogBroadcaster($logger))->publish('module.users', 'changed', ['id' => 7]);

        $this->assertSame(
            [['level' => 'info', 'message' => 'Broadcast changed on module.users', 'context' => [
                'topic' => 'module.users',
                'event' => 'changed',
                'data' => ['id' => 7],
            ]]],
            $logger->records(),
        );
    }

    public function test_the_log_driver_refuses_a_bad_topic_and_logs_nothing(): void
    {
        $logger = new CapturingLogger;

        try {
            (new LogBroadcaster($logger))->publish('Users', 'changed');
            $this->fail('A bad topic must throw.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([], $logger->records());
    }

    public function test_the_log_driver_refuses_an_oversized_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the limit is 65536');

        (new LogBroadcaster(new CapturingLogger))->publish('users', 'changed', ['p' => str_repeat('x', 70_000)]);
    }

    public function test_the_null_driver_accepts_a_good_publish(): void
    {
        (new NullBroadcaster)->publish('users', 'changed', ['id' => 1]);

        $this->addToAssertionCount(1);
    }

    public function test_the_null_driver_still_refuses_a_bad_topic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"module:users" is not a valid broadcast topic');

        (new NullBroadcaster)->publish('module:users', 'changed');
    }

    public function test_the_null_driver_still_refuses_a_bad_event_and_an_oversized_payload(): void
    {
        foreach ([['users', 'a b', []], ['users', 'changed', ['p' => str_repeat('x', 70_000)]]] as [$topic, $event, $data]) {
            try {
                (new NullBroadcaster)->publish($topic, $event, $data);
                $this->fail("{$event} must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
