<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FakeExceptionReporter;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Scheduler\Contracts\RunLogInterface;
use Hydra\Scheduler\Contracts\TaskInterface;
use Hydra\Scheduler\LockDirectory;
use Hydra\Scheduler\NullRunLog;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\TaskRun;
use Hydra\Scheduler\Tests\Support\BacklogBatch;
use Hydra\Scheduler\Tests\Support\FailingTask;
use Hydra\Scheduler\Tests\Support\NoteTask;
use Hydra\Scheduler\Tests\Support\RecordingRunLog;
use Hydra\Scheduler\Tests\Support\SlowBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(Runner::class)]
#[CoversClass(TaskRun::class)]
#[CoversClass(NullRunLog::class)]
final class RunnerTest extends TestCase
{
    private string $dir;

    private FrozenClock $clock;

    private Schedule $schedule;

    private FakeContainer $container;

    private CapturingLogger $log;

    private FakeExceptionReporter $reporter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-scheduler-' . bin2hex(random_bytes(4));
        $this->clock = new FrozenClock('2026-09-23T04:00:00+00:00');
        $this->schedule = new Schedule(new DateTimeZone('UTC'));
        $this->container = new FakeContainer;
        $this->log = new CapturingLogger;
        $this->reporter = new FakeExceptionReporter;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function test_only_due_tasks_run(): void
    {
        $due = $this->bind(new NoteTask);
        $this->schedule->run(NoteTask::class)->dailyAt('04:00');
        $this->schedule->drain(BacklogBatch::class)->dailyAt('05:00');

        $runs = $this->runner()->run();

        $this->assertSame(1, $due->runs);
        $this->assertSame([[NoteTask::class, Outcome::Ran]], self::summary($runs));
    }

    public function test_a_batch_is_called_until_it_reports_nothing_left(): void
    {
        $batch = $this->bind(new BacklogBatch(remaining: 50));
        $this->schedule->drain(BacklogBatch::class)->everyMinute();

        $runs = $this->runner()->run();

        $this->assertSame(4, $batch->calls);
        $this->assertSame(50, $runs[0]->items);
    }

    public function test_a_caught_up_batch_costs_one_call(): void
    {
        $batch = $this->bind(new BacklogBatch(remaining: 0));
        $this->schedule->drain(BacklogBatch::class)->everyMinute();

        $this->assertSame(0, $this->runner()->run()[0]->items);
        $this->assertSame(1, $batch->calls);
    }

    public function test_a_batch_stops_once_its_budget_is_spent(): void
    {
        $batch = $this->bind(new SlowBatch($this->clock));
        $this->schedule->drain(SlowBatch::class)->everyMinute()->for(25);

        $runs = $this->runner()->run();

        // 10, 20, then 30 minutes in: past 25, so no fourth call.
        $this->assertSame(3, $batch->calls);
        $this->assertSame(60, $runs[0]->items);
    }

    public function test_a_failure_is_logged_and_the_rest_still_run(): void
    {
        $this->bind(new FailingTask);
        $after = $this->bind(new NoteTask);
        $this->schedule->run(FailingTask::class)->everyMinute();
        $this->schedule->run(NoteTask::class)->everyMinute();

        $runs = $this->runner()->run();

        $this->assertSame(Outcome::Failed, $runs[0]->outcome);
        $this->assertSame(1, $after->runs);
        $this->assertTrue($this->log->has('Scheduled task ' . FailingTask::class . ' failed: the disk is full'));
    }

    public function test_a_failure_is_reported_with_its_task(): void
    {
        $this->bind(new FailingTask);
        $this->bind(new NoteTask);
        $this->schedule->run(FailingTask::class)->everyMinute();
        $this->schedule->run(NoteTask::class)->everyMinute();

        $this->runner()->run();

        $this->assertSame(['task' => FailingTask::class], $this->reporter->assertReported(RuntimeException::class)['context']);
        $this->assertCount(1, $this->reporter->reports());
    }

    public function test_a_reporter_that_throws_is_logged_and_the_tick_goes_on(): void
    {
        $this->bind(new FailingTask);
        $after = $this->bind(new NoteTask);
        $this->schedule->run(FailingTask::class)->everyMinute();
        $this->schedule->run(NoteTask::class)->everyMinute();
        $reporter = new class implements ExceptionReporterInterface {
            public function report(Throwable $e, array $context = []): void
            {
                throw new LogicException('tracker down');
            }
        };

        $runs = (new Runner($this->schedule, $this->container, $this->clock, $this->locks(), $this->log, $reporter))->run();

        $this->assertSame(Outcome::Failed, $runs[0]->outcome);
        $this->assertSame(1, $after->runs);
        $this->assertTrue($this->log->has('exception reporter failed: tracker down'));
    }

    public function test_the_lock_is_released_after_a_failure(): void
    {
        $this->bind(new FailingTask);
        $this->schedule->run(FailingTask::class)->everyMinute();

        $this->runner()->run();

        $this->assertNotNull($this->locks()->acquire(FailingTask::class, $this->clock->now()));
    }

    public function test_a_task_whose_lock_is_held_is_skipped(): void
    {
        $task = $this->bind(new NoteTask);
        $this->schedule->run(NoteTask::class)->everyMinute();
        $held = $this->locks()->acquire(NoteTask::class, $this->clock->now()->modify('-5 minutes'));

        $runs = $this->runner()->run();

        $this->assertSame(0, $task->runs);
        $this->assertSame([[NoteTask::class, Outcome::Held]], self::summary($runs));
        $this->assertSame(5, $runs[0]->heldMinutes);
        $this->assertSame([], $this->log->records());
        $this->assertNotNull($held);
    }

    public function test_a_lock_held_past_its_warning_age_is_reported_and_not_taken(): void
    {
        $task = $this->bind(new NoteTask);
        $this->schedule->run(NoteTask::class)->everyMinute()->warnAfter(30);
        $held = $this->locks()->acquire(NoteTask::class, $this->clock->now()->modify('-90 minutes'));

        $runs = $this->runner()->run();

        $this->assertSame(0, $task->runs);
        $this->assertSame(90, $runs[0]->heldMinutes);
        $this->assertTrue($this->log->has('Scheduled task ' . NoteTask::class . ' has held its lock for 90 minutes and may be hung; it is not being run again beside it.'));
        $this->assertNotNull($held);
    }

    public function test_a_container_that_builds_the_wrong_thing_is_a_failure(): void
    {
        $this->container->instance(NoteTask::class, new stdClass);
        $this->schedule->run(NoteTask::class)->everyMinute();

        $this->assertSame(Outcome::Failed, $this->runner()->run()[0]->outcome);
        $this->assertStringContainsString('is not a', $this->log->messages()[0]);
    }

    public function test_due_is_read_at_the_start_of_the_tick(): void
    {
        $this->bind(new SlowBatch($this->clock));
        $later = $this->bind(new NoteTask);
        $this->schedule->drain(SlowBatch::class)->everyMinute()->for(15);
        $this->schedule->run(NoteTask::class)->dailyAt('04:00');

        $this->runner()->run();

        // The batch ran the clock to 04:20 before the task's turn came; it was
        // due at 04:00, when the tick began, so it still runs.
        $this->assertSame(1, $later->runs);
    }

    public function test_each_run_is_recorded_with_when_it_started_and_how_long_it_took(): void
    {
        $this->bind(new SlowBatch($this->clock));
        $this->bind(new FailingTask);
        $this->schedule->drain(SlowBatch::class)->everyMinute()->for(15);
        $this->schedule->run(FailingTask::class)->everyMinute();
        $log = new RecordingRunLog;

        $runs = $this->runner($log)->run();

        $this->assertSame($runs, $log->runs);
        [$batch, $failed] = $log->runs;
        $this->assertSame('2026-09-23T04:00:00+00:00', $batch->startedAt?->format(DATE_ATOM));
        $this->assertSame(20 * 60_000, $batch->durationMs);
        $this->assertNull($batch->error);
        $this->assertSame('2026-09-23T04:20:00+00:00', $failed->startedAt?->format(DATE_ATOM));
        $this->assertSame(0, $failed->durationMs);
        $this->assertSame('RuntimeException: the disk is full', $failed->error);
    }

    public function test_a_held_run_is_recorded_without_a_duration(): void
    {
        $this->bind(new NoteTask);
        $this->schedule->run(NoteTask::class)->everyMinute();
        $held = $this->locks()->acquire(NoteTask::class, $this->clock->now());
        $log = new RecordingRunLog;

        $this->runner($log)->run();

        $this->assertSame(Outcome::Held, $log->runs[0]->outcome);
        $this->assertSame('2026-09-23T04:00:00+00:00', $log->runs[0]->startedAt?->format(DATE_ATOM));
        $this->assertNull($log->runs[0]->durationMs);
        $this->assertNotNull($held);
    }

    public function test_an_error_is_kept_to_one_line(): void
    {
        $this->container->instance(NoteTask::class, new class implements TaskInterface {
            public function run(): void
            {
                throw new RuntimeException("first\nsecond\r\nthird");
            }
        });
        $this->schedule->run(NoteTask::class)->everyMinute();
        $log = new RecordingRunLog;

        $this->runner($log)->run();

        $this->assertSame('RuntimeException: first second third', $log->runs[0]->error);
    }

    public function test_a_run_log_that_fails_stops_nothing(): void
    {
        $first = $this->bind(new NoteTask);
        $second = $this->bind(new BacklogBatch(remaining: 3));
        $this->schedule->run(NoteTask::class)->everyMinute();
        $this->schedule->drain(BacklogBatch::class)->everyMinute();
        $log = new class implements RunLogInterface {
            public function record(TaskRun $run): void
            {
                throw new RuntimeException('database gone');
            }
        };

        $runs = $this->runner($log)->run();

        $this->assertSame(1, $first->runs);
        $this->assertSame(0, $second->remaining);
        $this->assertSame([Outcome::Ran, Outcome::Ran], array_map(static fn (TaskRun $run): Outcome => $run->outcome, $runs));
        $this->assertTrue($this->log->has('Could not record the run of ' . NoteTask::class . ': database gone'));
    }

    /**
     * @param list<TaskRun> $runs
     * @return list<array{string, Outcome}>
     */
    private static function summary(array $runs): array
    {
        return array_map(static fn (TaskRun $run): array => [$run->class, $run->outcome], $runs);
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

    private function locks(): LockDirectory
    {
        return new LockDirectory($this->dir);
    }

    private function runner(?RunLogInterface $runs = null): Runner
    {
        return new Runner($this->schedule, $this->container, $this->clock, $this->locks(), $this->log, $this->reporter, $runs ?? new NullRunLog);
    }
}
