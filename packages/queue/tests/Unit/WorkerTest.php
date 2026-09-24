<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FakeExceptionReporter;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Tests\Support\FailingJob;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use Hydra\Queue\Worker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    private FakeConnection $db;

    private FrozenClock $clock;

    private DatabaseQueue $queue;

    private FakeContainer $container;

    private CapturingLogger $log;

    private FakeExceptionReporter $reporter;

    protected function setUp(): void
    {
        $this->db = FakeConnection::inMemory();
        QueueTables::create($this->db);
        $this->clock = new FrozenClock('2026-09-23T04:00:00+00:00');
        $this->queue = new DatabaseQueue($this->db, $this->clock, 'sqlite');
        $this->container = new FakeContainer;
        $this->log = new CapturingLogger;
        $this->reporter = new FakeExceptionReporter;
    }

    public function test_a_finished_job_is_handed_its_payload_and_deleted(): void
    {
        $job = $this->bind(new RecordingJob);
        $this->queue->push(RecordingJob::class, ['to' => 'ada@example.com']);

        $this->assertSame(1, $this->worker()->batch());

        $this->assertSame([['to' => 'ada@example.com']], $job->handled);
        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
        $this->assertSame(0, $this->worker()->batch());
    }

    public function test_a_batch_is_capped_and_reports_what_it_took(): void
    {
        $job = $this->bind(new RecordingJob);
        foreach (range(1, 3) as $n) {
            $this->queue->push(RecordingJob::class, ['n' => $n]);
        }

        $this->assertSame(2, $this->worker(batchSize: 2)->batch());
        $this->assertSame(1, $this->worker(batchSize: 2)->batch());
        $this->assertCount(3, $job->handled);
    }

    public function test_a_failure_waits_out_the_backoff_then_moves_to_failed_jobs(): void
    {
        $job = $this->bind(new FailingJob);
        $this->queue->push(FailingJob::class);

        $this->worker()->batch();
        $this->assertTrue($this->log->has('Queued job ' . FailingJob::class . ' failed on attempt 1 of 3 and will be tried again in 10 seconds: the mail server is down'));

        $this->clock->advance('+9 seconds');
        $this->assertSame(0, $this->worker()->batch());
        $this->clock->advance('+1 second');
        $this->worker()->batch();
        $this->assertTrue($this->log->has('Queued job ' . FailingJob::class . ' failed on attempt 2 of 3 and will be tried again in 60 seconds: the mail server is down'));

        $this->clock->advance('+60 seconds');
        $this->worker()->batch();

        $this->assertSame(3, $job->attempts);
        $this->assertTrue($this->log->has('Queued job ' . FailingJob::class . ' failed on attempt 3 of 3 and was moved to failed_jobs: the mail server is down'));
        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
        $this->assertCount(1, $this->db->select('SELECT id FROM failed_jobs'));
    }

    public function test_only_the_failure_that_moves_a_job_to_failed_jobs_is_reported(): void
    {
        $this->bind(new FailingJob);
        $this->queue->push(FailingJob::class);
        $id = $this->db->select('SELECT id FROM jobs')[0]['id'];

        $this->worker(tries: 2)->batch();
        $this->reporter->assertNothingReported();

        $this->clock->advance('+10 seconds');
        $this->worker(tries: 2)->batch();

        $report = $this->reporter->assertReported(RuntimeException::class);
        $this->assertSame(['job' => FailingJob::class, 'id' => $id, 'attempt' => 2], $report['context']);
    }

    public function test_a_reporter_that_throws_is_logged_and_the_job_still_fails(): void
    {
        $this->bind(new FailingJob);
        $this->queue->push(FailingJob::class);
        $reporter = new class implements ExceptionReporterInterface {
            public function report(Throwable $e, array $context = []): void
            {
                throw new LogicException('tracker down');
            }
        };

        (new Worker($this->queue, $this->container, $this->log, tries: 1, reporter: $reporter))->batch();

        $this->assertTrue($this->log->has('exception reporter failed: tracker down'));
        $this->assertCount(1, $this->db->select('SELECT id FROM failed_jobs'));
    }

    public function test_the_last_backoff_repeats(): void
    {
        $this->bind(new FailingJob);
        $this->queue->push(FailingJob::class);
        $worker = $this->worker(tries: 5, backoff: [5]);

        $worker->batch();
        $this->clock->advance('+5 seconds');
        $worker->batch();

        $this->assertTrue($this->log->has('Queued job ' . FailingJob::class . ' failed on attempt 2 of 5 and will be tried again in 5 seconds: the mail server is down'));
    }

    public function test_one_failure_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->bind(new FailingJob);
        $ok = $this->bind(new RecordingJob);
        $this->queue->push(FailingJob::class);
        $this->queue->push(RecordingJob::class);

        $this->assertSame(2, $this->worker()->batch());
        $this->assertCount(1, $ok->handled);
    }

    public function test_something_the_container_builds_that_is_not_a_job_fails_as_a_job(): void
    {
        $this->container->instance(RecordingJob::class, new stdClass);
        $this->queue->push(RecordingJob::class);

        $this->worker(tries: 1)->batch();

        $this->assertTrue($this->log->has(
            'Queued job ' . RecordingJob::class . ' failed on attempt 1 of 1 and was moved to failed_jobs: The container built ' . RecordingJob::class . ' as something that is not a Hydra\Queue\Contracts\JobInterface.',
        ));
    }

    public function test_the_settings_are_checked(): void
    {
        foreach ([
            fn () => $this->worker(tries: 0),
            fn () => $this->worker(batchSize: 0),
            fn () => $this->worker(backoff: []),
            fn () => $this->worker(backoff: [10, -1]),
        ] as $call) {
            try {
                $call();
                $this->fail('A bad setting was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @template T of object
     * @param T $instance
     * @return T
     */
    private function bind(object $instance): object
    {
        $this->container->instance($instance::class, $instance);

        return $instance;
    }

    /** @param list<int> $backoff */
    private function worker(int $tries = Worker::DEFAULT_TRIES, array $backoff = Worker::DEFAULT_BACKOFF, int $batchSize = Worker::DEFAULT_BATCH): Worker
    {
        return new Worker($this->queue, $this->container, $this->log, $tries, $backoff, $batchSize, $this->reporter);
    }
}
