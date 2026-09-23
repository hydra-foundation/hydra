<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeZone;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Scheduler\Console\ScheduleRunCommand;
use Hydra\Scheduler\LockDirectory;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\Tests\Support\BacklogBatch;
use Hydra\Scheduler\Tests\Support\FailingTask;
use Hydra\Scheduler\Tests\Support\NoteTask;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ScheduleRunCommand::class)]
final class ScheduleRunCommandTest extends CommandContractTestCase
{
    private string $dir;

    private Schedule $schedule;

    private FakeContainer $container;

    public static function commands(): iterable
    {
        yield 'schedule:run' => new ScheduleRunCommand(self::runner(new Schedule(new DateTimeZone('UTC')), new FakeContainer, sys_get_temp_dir()));
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-scheduler-' . bin2hex(random_bytes(4));
        $this->schedule = new Schedule(new DateTimeZone('UTC'));
        $this->container = new FakeContainer;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.lock') ?: []);
        @rmdir($this->dir);
    }

    public function test_a_quiet_minute_says_so(): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->command()->execute(new ArrayInput, $output));
        $output->assertSaid('Nothing is due.');
    }

    public function test_each_run_is_a_row(): void
    {
        $this->container->instance(NoteTask::class, new NoteTask);
        $this->container->instance(BacklogBatch::class, new BacklogBatch(remaining: 30));
        $this->schedule->run(NoteTask::class)->everyMinute();
        $this->schedule->drain(BacklogBatch::class)->everyMinute();
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->command()->execute(new ArrayInput, $output));
        $this->assertSame([
            [NoteTask::class, 'ran', ''],
            [BacklogBatch::class, 'ran', '30 items'],
        ], $output->tables()[0]['rows']);
    }

    public function test_a_held_task_says_how_long_it_has_run(): void
    {
        $this->container->instance(NoteTask::class, new NoteTask);
        $this->schedule->run(NoteTask::class)->everyMinute();
        $held = (new LockDirectory($this->dir))->acquire(NoteTask::class, (new FrozenClock)->now()->modify('-7 minutes'));
        $output = new FakeOutput;

        $this->command()->execute(new ArrayInput, $output);

        $this->assertSame([NoteTask::class, 'held', 'running for 7 min'], $output->tables()[0]['rows'][0]);
        $this->assertNotNull($held);
    }

    public function test_a_failed_task_fails_the_command(): void
    {
        $this->container->instance(FailingTask::class, new FailingTask);
        $this->schedule->run(FailingTask::class)->everyMinute();
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Failure, $this->command()->execute(new ArrayInput, $output));
        $this->assertSame([FailingTask::class, 'failed', 'see the log'], $output->tables()[0]['rows'][0]);
    }

    private function command(): ScheduleRunCommand
    {
        return new ScheduleRunCommand(self::runner($this->schedule, $this->container, $this->dir));
    }

    private static function runner(Schedule $schedule, FakeContainer $container, string $dir): Runner
    {
        return new Runner($schedule, $container, new FrozenClock, new LockDirectory($dir), new CapturingLogger);
    }
}
