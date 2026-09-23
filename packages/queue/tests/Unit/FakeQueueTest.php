<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Queue\Testing\FakeQueue;
use Hydra\Queue\Testing\QueuedJob;
use Hydra\Queue\Tests\Support\FailingJob;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeQueue::class)]
#[CoversClass(QueuedJob::class)]
final class FakeQueueTest extends TestCase
{
    public function test_it_records_each_push_as_the_job_would_receive_it(): void
    {
        $queue = new FakeQueue;
        $queue->push(RecordingJob::class, ['ratio' => 1.0, 'empty' => []], delay: 30);

        [$pushed] = $queue->pushed();

        $this->assertSame(RecordingJob::class, $pushed->job);
        $this->assertSame(['ratio' => 1.0, 'empty' => []], $pushed->payload);
        $this->assertSame(30, $pushed->delay);
    }

    public function test_it_asserts_on_what_was_queued(): void
    {
        $queue = new FakeQueue;
        $queue->assertNothingQueued();

        $queue->push(RecordingJob::class, ['to' => 'ada@example.com']);
        $queue->push(RecordingJob::class, ['to' => 'grace@example.com']);

        $queue->assertQueued(RecordingJob::class);
        $queue->assertQueued(RecordingJob::class, times: 2);
        $queue->assertQueued(RecordingJob::class, static fn (array $p): bool => $p['to'] === 'ada@example.com', times: 1);
        $queue->assertNotQueued(FailingJob::class);
        $queue->assertNotQueued(RecordingJob::class, static fn (array $p): bool => $p['to'] === 'alan@example.com');
        $this->assertCount(1, $queue->pushed(matching: static fn (array $p, QueuedJob $q): bool => $q->delay === 0 && $p['to'] === 'grace@example.com'));
    }

    public function test_each_assertion_fails_when_it_should(): void
    {
        $queue = new FakeQueue;
        $queue->push(RecordingJob::class);

        foreach ([
            static fn () => $queue->assertQueued(FailingJob::class),
            static fn () => $queue->assertQueued(RecordingJob::class, times: 2),
            static fn () => $queue->assertNotQueued(RecordingJob::class),
            static fn () => $queue->assertNothingQueued(),
        ] as $assertion) {
            try {
                $assertion();
                $this->fail('The assertion passed.');
            } catch (AssertionFailedError $e) {
                $this->assertStringNotContainsString('The assertion passed.', $e->getMessage());
            }
        }
    }
}
