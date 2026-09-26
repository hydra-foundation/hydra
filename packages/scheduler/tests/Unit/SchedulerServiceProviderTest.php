<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FakeExceptionReporter;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Scheduler\Contracts\RunLogInterface;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\SchedulerServiceProvider;
use Hydra\Scheduler\Tests\Support\FailingTask;
use Hydra\Scheduler\Tests\Support\NoteTask;
use Hydra\Scheduler\Tests\Support\RecordingRunLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(SchedulerServiceProvider::class)]
final class SchedulerServiceProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-scheduler-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.lock') ?: []);
        @rmdir($this->dir);
    }

    public function test_the_runner_runs_the_schedule_the_application_filled(): void
    {
        // The application declares into the container's Schedule from its own
        // boot(); a runner holding a different instance would find it empty.
        $container = $this->container();
        $task = new NoteTask;
        $container->instance(NoteTask::class, $task);

        $container->get(Schedule::class)->run(NoteTask::class)->everyMinute();
        $runs = $container->get(Runner::class)->run();

        $this->assertSame(Outcome::Ran, $runs[0]->outcome);
        $this->assertSame(1, $task->runs);
        $this->assertFileExists($this->dir . '/' . str_replace('\\', '-', NoteTask::class) . '.lock');
    }

    public function test_a_bound_exception_reporter_reaches_the_runner(): void
    {
        $container = $this->container();
        $reporter = new FakeExceptionReporter;
        $container->instance(ExceptionReporterInterface::class, $reporter);
        $container->instance(FailingTask::class, new FailingTask);

        $container->get(Schedule::class)->run(FailingTask::class)->everyMinute();
        $container->get(Runner::class)->run();

        $reporter->assertReported(RuntimeException::class);
    }

    public function test_a_bound_run_log_is_what_the_runner_records_to(): void
    {
        $container = $this->container();
        $log = new RecordingRunLog;
        $container->instance(RunLogInterface::class, $log);
        $container->instance(NoteTask::class, new NoteTask);

        $container->get(Schedule::class)->run(NoteTask::class)->everyMinute();
        $container->get(Runner::class)->run();

        $this->assertSame(NoteTask::class, $log->runs[0]->class);
    }

    private function container(): ContainerInterface
    {
        $container = new FakeContainer([
            Environment::class => new Environment(__DIR__),
            ClockInterface::class => new FrozenClock,
            LoggerInterface::class => new CapturingLogger,
        ]);

        (new SchedulerServiceProvider($this->dir))->register($container);

        return $container;
    }
}
