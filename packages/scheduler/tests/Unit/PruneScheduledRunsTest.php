<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Scheduler\DatabaseRunLog;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\PruneScheduledRuns;
use Hydra\Scheduler\SchedulerConfig;
use Hydra\Scheduler\TaskRun;
use Hydra\Scheduler\Tests\Support\NoteTask;
use Hydra\Scheduler\Tests\Support\RunTables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PruneScheduledRuns::class)]
final class PruneScheduledRunsTest extends TestCase
{
    public function test_it_prunes_to_the_configured_number_of_days(): void
    {
        $db = FakeConnection::inMemory();
        RunTables::create($db);
        $runs = new DatabaseRunLog($db, new FrozenClock('2026-09-26T12:00:00+00:00'));

        foreach (['2026-09-23T12:00:00+00:00', '2026-09-25T12:00:00+00:00'] as $at) {
            $runs->record(new TaskRun(NoteTask::class, Outcome::Ran, startedAt: new DateTimeImmutable($at), durationMs: 1));
        }

        (new PruneScheduledRuns($runs, new SchedulerConfig(new DateTimeZone('UTC'), '/tmp', keepDays: 2)))->run();

        $this->assertSame(['2026-09-25T12:00:00+00:00'], array_map(
            static fn (TaskRun $run): string => (string) $run->startedAt?->format(DATE_ATOM),
            array_values($runs->latest()),
        ));
    }
}
