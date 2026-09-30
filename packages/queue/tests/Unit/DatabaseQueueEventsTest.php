<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Event\Testing\FakeDispatcher;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Events\QueueChanged;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the queue says when its tables move, for whoever shows them. It names
 * the tables and nothing else: a list refetches. The per-job calls a worker
 * makes are left to the worker, which says it once per batch.
 */
#[CoversClass(DatabaseQueue::class)]
#[CoversClass(QueueChanged::class)]
final class DatabaseQueueEventsTest extends TestCase
{
    private FakeConnection $db;
    private FakeDispatcher $events;
    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        $this->db = FakeConnection::inMemory();
        QueueTables::create($this->db);
        $this->events = new FakeDispatcher;
        $this->queue = new DatabaseQueue($this->db, new FrozenClock('2026-09-23T04:00:00+00:00'), 'sqlite', events: $this->events);
    }

    public function test_a_push_moves_the_jobs(): void
    {
        $this->queue->push(RecordingJob::class);

        $this->assertChanged([QueueChanged::JOBS]);
    }

    public function test_a_retry_moves_both_tables(): void
    {
        $id = $this->failOne();

        $this->assertTrue($this->queue->retry($id));

        $this->assertChanged([QueueChanged::JOBS, QueueChanged::FAILED]);
    }

    public function test_a_cancel_moves_the_jobs(): void
    {
        $this->queue->push(RecordingJob::class);
        $this->events->reset();

        $this->assertTrue($this->queue->cancel(1));

        $this->assertChanged([QueueChanged::JOBS]);
    }

    public function test_a_forget_and_a_flush_move_the_failures(): void
    {
        $this->assertTrue($this->queue->forget($this->failOne()));
        $this->assertChanged([QueueChanged::FAILED]);

        $this->failOne();
        $this->assertSame(1, $this->queue->flush());
        $this->assertChanged([QueueChanged::FAILED]);
    }

    public function test_what_changed_nothing_says_nothing(): void
    {
        $this->assertFalse($this->queue->retry(99));
        $this->assertFalse($this->queue->cancel(99));
        $this->assertFalse($this->queue->forget(99));
        $this->assertSame(0, $this->queue->flush());

        $this->events->assertNothingDispatched();
    }

    public function test_a_held_job_that_cannot_be_cancelled_says_nothing(): void
    {
        $this->queue->push(RecordingJob::class);
        $this->queue->reserve(1);
        $this->events->reset();

        $this->assertFalse($this->queue->cancel(1));

        $this->events->assertNothingDispatched();
    }

    public function test_the_workers_own_calls_are_left_to_the_worker(): void
    {
        $this->queue->push(RecordingJob::class);
        $this->queue->push(RecordingJob::class);
        $this->events->reset();

        [$done, $retried] = $this->queue->reserve(2);
        $this->queue->delete($done);
        $this->queue->release($retried, 10);
        $this->queue->push(RecordingJob::class);
        $this->events->reset();
        [$failing] = $this->queue->reserve(5);
        $this->queue->fail($failing, new RuntimeException('no'));

        $this->events->assertNothingDispatched();
    }

    public function test_without_a_dispatcher_nothing_is_asked_of_one(): void
    {
        $queue = new DatabaseQueue($this->db, new FrozenClock('2026-09-23T04:00:00+00:00'), 'sqlite');

        $queue->push(RecordingJob::class);

        $this->assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM jobs')['n']);
    }

    /** A job in failed_jobs, and its id there; the events it took are dropped. */
    private function failOne(): int
    {
        $this->queue->push(RecordingJob::class);
        [$job] = $this->queue->reserve(1);
        $this->queue->fail($job, new RuntimeException('no'));
        $this->events->reset();

        return (int) $this->db->selectOne('SELECT MAX(id) AS id FROM failed_jobs')['id'];
    }

    /** @param list<string> $tables */
    private function assertChanged(array $tables): void
    {
        $dispatched = $this->events->dispatched(QueueChanged::class);

        $this->assertCount(1, $dispatched);
        $this->assertSame($tables, $dispatched[0]->tables);
        $this->events->reset();
    }
}
