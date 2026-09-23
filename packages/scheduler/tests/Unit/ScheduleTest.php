<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\ScheduledTask;
use Hydra\Scheduler\Tests\Support\BacklogBatch;
use Hydra\Scheduler\Tests\Support\NoteTask;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Schedule::class)]
#[CoversClass(ScheduledTask::class)]
final class ScheduleTest extends TestCase
{
    public function test_the_helpers_write_the_cron_they_stand_for(): void
    {
        $schedule = $this->schedule();

        $this->assertSame('* * * * *', $schedule->run(NoteTask::class)->everyMinute()->expression()->expression);
        $this->assertSame('0 * * * *', $this->schedule()->run(NoteTask::class)->hourly()->expression()->expression);
        $this->assertSame('5 4 * * *', $this->schedule()->run(NoteTask::class)->dailyAt('04:05')->expression()->expression);
        $this->assertSame('0 0 * * 0', $this->schedule()->run(NoteTask::class)->weekly()->expression()->expression);
    }

    public function test_daily_at_refuses_a_time_that_is_not_hh_mm(): void
    {
        foreach (['4:00', '24:00', '04:60', '4am', ''] as $time) {
            try {
                $this->schedule()->run(NoteTask::class)->dailyAt($time);
                $this->fail("dailyAt('{$time}') was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_times_are_read_in_the_schedules_zone(): void
    {
        // Edmonton is UTC-6 in September.
        $schedule = $this->schedule('America/Edmonton');
        $schedule->run(NoteTask::class)->dailyAt('04:00');

        $this->assertCount(1, $schedule->due(new DateTimeImmutable('2026-09-23 10:00 UTC')));
        $this->assertCount(0, $schedule->due(new DateTimeImmutable('2026-09-23 04:00 UTC')));
    }

    public function test_only_what_is_due_is_returned_in_declared_order(): void
    {
        $schedule = $this->schedule();
        $schedule->drain(BacklogBatch::class)->everyMinute();
        $schedule->run(NoteTask::class)->hourly();

        $at = static fn (string $time): array => array_map(
            static fn (ScheduledTask $task): string => $task->class,
            $schedule->due(new DateTimeImmutable("2026-09-23 {$time} UTC")),
        );

        $this->assertSame([BacklogBatch::class, NoteTask::class], $at('10:00'));
        $this->assertSame([BacklogBatch::class], $at('10:01'));
    }

    public function test_it_lists_every_entry_and_keeps_its_zone(): void
    {
        $schedule = $this->schedule('America/Edmonton');
        $schedule->run(NoteTask::class)->hourly();
        $schedule->drain(BacklogBatch::class)->everyMinute();

        $this->assertSame(
            [NoteTask::class, BacklogBatch::class],
            array_map(static fn (ScheduledTask $task): string => $task->class, $schedule->tasks()),
        );
        $this->assertSame('America/Edmonton', $schedule->timezone()->getName());
    }

    public function test_run_takes_a_task_and_drain_takes_a_batch(): void
    {
        $this->assertFalse($this->schedule()->run(NoteTask::class)->batched);
        $this->assertTrue($this->schedule()->drain(BacklogBatch::class)->batched);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement');

        $this->schedule()->run(BacklogBatch::class);
    }

    public function test_a_class_that_is_neither_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        $this->schedule()->drain(stdClass::class);
    }

    public function test_a_class_is_scheduled_once(): void
    {
        $schedule = $this->schedule();
        $schedule->run(NoteTask::class)->hourly();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already scheduled');

        $schedule->run(NoteTask::class)->everyMinute();
    }

    public function test_an_entry_with_no_frequency_says_so_when_asked(): void
    {
        $schedule = $this->schedule();
        $schedule->run(NoteTask::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no frequency');

        $schedule->due(new DateTimeImmutable('2026-09-23 10:00 UTC'));
    }

    public function test_the_warning_defaults_to_an_hour_and_a_batch_to_no_budget(): void
    {
        $task = $this->schedule()->drain(BacklogBatch::class)->everyMinute();

        $this->assertSame(60, $task->warnMinutes());
        $this->assertNull($task->budgetMinutes());

        $task->warnAfter(10)->for(50);

        $this->assertSame(10, $task->warnMinutes());
        $this->assertSame(50, $task->budgetMinutes());
    }

    public function test_a_budget_is_only_for_a_batch(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('drain()');

        $this->schedule()->run(NoteTask::class)->for(5);
    }

    public function test_minutes_must_be_at_least_one(): void
    {
        foreach ([
            fn () => $this->schedule()->drain(BacklogBatch::class)->for(0),
            fn () => $this->schedule()->run(NoteTask::class)->warnAfter(0),
        ] as $call) {
            try {
                $call();
                $this->fail('Zero minutes was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function schedule(string $zone = 'UTC'): Schedule
    {
        return new Schedule(new DateTimeZone($zone));
    }
}
