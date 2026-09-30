<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Event\Testing\FakeDispatcher;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Events\QueueChanged;
use Hydra\Queue\Tests\Support\FailingJob;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use Hydra\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A worker says what its batch moved once, not once per job: a drain of a
 * thousand jobs in batches of twenty is fifty refetches, not three thousand.
 */
#[CoversClass(Worker::class)]
final class WorkerEventsTest extends TestCase
{
    private DatabaseQueue $queue;
    private FakeContainer $container;
    private FakeDispatcher $events;

    protected function setUp(): void
    {
        $db = FakeConnection::inMemory();
        QueueTables::create($db);
        $this->queue = new DatabaseQueue($db, new FrozenClock('2026-09-23T04:00:00+00:00'), 'sqlite');
        $this->container = new FakeContainer;
        $this->container->instance(RecordingJob::class, new RecordingJob);
        $this->container->instance(FailingJob::class, new FailingJob);
        $this->events = new FakeDispatcher;
    }

    public function test_an_empty_batch_says_nothing(): void
    {
        $this->assertSame(0, $this->worker()->batch());

        $this->events->assertNothingDispatched();
    }

    public function test_a_batch_of_finished_and_retried_jobs_moves_the_jobs_once(): void
    {
        $this->queue->push(RecordingJob::class);
        $this->queue->push(RecordingJob::class);
        $this->queue->push(FailingJob::class);

        $this->assertSame(3, $this->worker(tries: 3)->batch());

        $this->assertChanged([QueueChanged::JOBS]);
    }

    public function test_a_batch_that_runs_a_job_out_of_tries_moves_the_failures_too(): void
    {
        $this->queue->push(RecordingJob::class);
        $this->queue->push(FailingJob::class);

        $this->worker(tries: 1)->batch();

        $this->assertChanged([QueueChanged::JOBS, QueueChanged::FAILED]);
    }

    public function test_the_next_batch_starts_clean(): void
    {
        $this->queue->push(FailingJob::class);
        $worker = $this->worker(tries: 1);
        $worker->batch();
        $this->events->reset();
        $this->queue->push(RecordingJob::class);
        $this->events->reset();

        $worker->batch();

        $this->assertChanged([QueueChanged::JOBS]);
    }

    private function worker(int $tries = Worker::DEFAULT_TRIES): Worker
    {
        return new Worker($this->queue, $this->container, new CapturingLogger, $tries, events: $this->events);
    }

    /** @param list<string> $tables */
    private function assertChanged(array $tables): void
    {
        $dispatched = $this->events->dispatched(QueueChanged::class);

        $this->assertCount(1, $dispatched);
        $this->assertSame($tables, $dispatched[0]->tables);
    }
}
