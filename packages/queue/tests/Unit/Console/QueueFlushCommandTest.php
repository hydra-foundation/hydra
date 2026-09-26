<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit\Console;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\Console\QueueFlushCommand;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(QueueFlushCommand::class)]
final class QueueFlushCommandTest extends CommandContractTestCase
{
    private DatabaseQueue $queue;

    public static function commands(): iterable
    {
        yield 'queue:flush' => new QueueFlushCommand(self::emptyQueue());
    }

    protected function setUp(): void
    {
        $this->queue = self::emptyQueue();
    }

    public function test_every_failed_job_is_deleted_and_counted(): void
    {
        $this->failJobs(3);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->flush($output));
        $output->assertSaid('3 failed jobs deleted.');
        $this->assertSame([], $this->queue->failed());
        $this->assertSame([], $this->queue->reserve(10));
    }

    public function test_a_single_job_is_counted_in_the_singular(): void
    {
        $this->failJobs(1);
        $output = new FakeOutput;

        $this->flush($output);
        $output->assertSaid('1 failed job deleted.');
    }

    public function test_nothing_failed_says_so(): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->flush($output));
        $output->assertSaid('No failed jobs.');
    }

    private function flush(FakeOutput $output): ExitCode
    {
        return (new QueueFlushCommand($this->queue))->execute(new ArrayInput, $output);
    }

    private function failJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->queue->push(RecordingJob::class, ['n' => $i]);
        }

        foreach ($this->queue->reserve($count) as $job) {
            $this->queue->fail($job, new RuntimeException('down'));
        }
    }

    private static function emptyQueue(): DatabaseQueue
    {
        $db = FakeConnection::inMemory();
        QueueTables::create($db);

        return new DatabaseQueue($db, new FrozenClock('2026-09-23T04:00:00+00:00'), 'sqlite');
    }
}
