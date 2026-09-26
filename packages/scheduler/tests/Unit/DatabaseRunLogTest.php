<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Scheduler\DatabaseRunLog;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\TaskRun;
use Hydra\Scheduler\Tests\Support\BacklogBatch;
use Hydra\Scheduler\Tests\Support\NoteTask;
use Hydra\Scheduler\Tests\Support\RunTables;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseRunLog::class)]
final class DatabaseRunLogTest extends TestCase
{
    private FakeConnection $db;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->db = FakeConnection::inMemory();
        RunTables::create($this->db);
        $this->clock = new FrozenClock('2026-09-26T12:00:00+00:00');
    }

    public function test_a_run_is_stored_with_its_times_in_unix_seconds(): void
    {
        $this->log()->record(new TaskRun(
            BacklogBatch::class,
            Outcome::Failed,
            items: 12,
            startedAt: new DateTimeImmutable('2026-09-26T11:59:00+00:00'),
            durationMs: 1500,
            error: 'RuntimeException: the disk is full',
        ));

        $this->assertSame([[
            'task' => BacklogBatch::class,
            'outcome' => 'failed',
            'items' => 12,
            'held_minutes' => null,
            'error' => 'RuntimeException: the disk is full',
            'started_at' => 1790423940,
            'duration_ms' => 1500,
        ]], $this->db->select('SELECT task, outcome, items, held_minutes, error, started_at, duration_ms FROM scheduled_runs'));
    }

    public function test_a_run_that_does_not_say_when_it_started_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('when it started');

        $this->log()->record(new TaskRun(NoteTask::class, Outcome::Ran));
    }

    public function test_the_latest_run_of_each_task_comes_back_as_a_run(): void
    {
        $this->record(NoteTask::class, Outcome::Ran, '2026-09-26T10:00:00+00:00');
        $this->record(BacklogBatch::class, Outcome::Ran, '2026-09-26T10:30:00+00:00', items: 0);
        $this->record(NoteTask::class, Outcome::Held, '2026-09-26T11:00:00+00:00', heldMinutes: 3);

        $latest = $this->log()->latest();

        $this->assertSame([NoteTask::class, BacklogBatch::class], array_keys($latest));
        $this->assertSame(Outcome::Held, $latest[NoteTask::class]->outcome);
        $this->assertSame(3, $latest[NoteTask::class]->heldMinutes);
        $this->assertSame('2026-09-26T11:00:00+00:00', $latest[NoteTask::class]->startedAt?->format(DATE_ATOM));
        $this->assertSame(0, $latest[BacklogBatch::class]->items);
        $this->assertSame(250, $latest[BacklogBatch::class]->durationMs);
    }

    public function test_numbers_read_back_as_strings_are_still_numbers(): void
    {
        $this->db->execute(
            "INSERT INTO scheduled_runs (task, outcome, items, held_minutes, error, started_at, duration_ms) VALUES (?, 'ran', '4', '2', NULL, '1790423940', '75')",
            [NoteTask::class],
        );

        $run = $this->log()->latest()[NoteTask::class];

        $this->assertSame([4, 2, 75], [$run->items, $run->heldMinutes, $run->durationMs]);
        $this->assertSame(1790423940, $run->startedAt?->getTimestamp());
    }

    public function test_prune_deletes_runs_older_than_the_days_given(): void
    {
        $this->record(NoteTask::class, Outcome::Ran, '2026-09-19T11:59:59+00:00');
        $this->record(NoteTask::class, Outcome::Ran, '2026-09-19T12:00:00+00:00');
        $this->record(NoteTask::class, Outcome::Ran, '2026-09-26T11:00:00+00:00');

        $this->assertSame(1, $this->log()->prune(7));
        $this->assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM scheduled_runs')['n']);
    }

    public function test_prune_keeps_at_least_a_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one day');

        $this->log()->prune(0);
    }

    /** @param class-string $task */
    private function record(string $task, Outcome $outcome, string $at, ?int $items = null, ?int $heldMinutes = null): void
    {
        $this->log()->record(new TaskRun($task, $outcome, $items, $heldMinutes, new DateTimeImmutable($at), 250));
    }

    private function log(): DatabaseRunLog
    {
        return new DatabaseRunLog($this->db, $this->clock);
    }
}
