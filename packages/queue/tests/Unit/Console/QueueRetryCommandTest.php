<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit\Console;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\Console\QueueRetryCommand;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass(QueueRetryCommand::class)]
final class QueueRetryCommandTest extends CommandContractTestCase
{
    private DatabaseQueue $queue;

    public static function commands(): iterable
    {
        yield 'queue:retry' => new QueueRetryCommand(self::emptyQueue());
    }

    protected function setUp(): void
    {
        $this->queue = self::emptyQueue();
    }

    public function test_one_job_goes_back_by_its_id(): void
    {
        $this->failJobs(2);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->retry(new ArrayInput(['id' => '1']), $output));
        $output->assertSaid('Job 1 is back on the queue.');
        $this->assertSame([2], array_map(static fn ($j): int => $j->id, $this->queue->failed()));
        $this->assertCount(1, $this->queue->reserve(10));
    }

    public function test_all_goes_back_together(): void
    {
        $this->failJobs(3);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->retry(new ArrayInput([], [], ['all']), $output));
        $output->assertSaid('3 jobs are back on the queue.');
        $this->assertSame([], $this->queue->failed());
        $this->assertCount(3, $this->queue->reserve(10));
    }

    public function test_all_counts_a_single_job_in_the_singular(): void
    {
        $this->failJobs(1);
        $output = new FakeOutput;

        $this->retry(new ArrayInput([], [], ['all']), $output);
        $output->assertSaid('1 job is back on the queue.');
    }

    public function test_all_with_nothing_failed_says_so(): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->retry(new ArrayInput([], [], ['all']), $output));
        $output->assertSaid('No failed jobs.');
    }

    /** @return iterable<string, array{ArrayInput}> */
    public static function ambiguous(): iterable
    {
        yield 'neither' => [new ArrayInput];
        yield 'both' => [new ArrayInput(['id' => '1'], [], ['all'])];
    }

    #[DataProvider('ambiguous')]
    public function test_it_needs_exactly_one_of_an_id_or_all(ArrayInput $input): void
    {
        $this->failJobs(1);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Failure, $this->retry($input, $output));
        $output->assertError('Name one failed job by its id, or pass --all.');
        $this->assertCount(1, $this->queue->failed());
    }

    /** @return iterable<string, array{string}> */
    public static function unknownIds(): iterable
    {
        yield 'missing' => ['9'];
        yield 'not a number' => ['abc'];
        yield 'negative' => ['-1'];
    }

    #[DataProvider('unknownIds')]
    public function test_an_unknown_id_fails_and_says_where_to_look(string $id): void
    {
        $this->failJobs(1);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Failure, $this->retry(new ArrayInput(['id' => $id]), $output));
        $output->assertError("No failed job has the id \"{$id}\". queue:failed lists them.");
        $this->assertCount(1, $this->queue->failed());
    }

    private function retry(ArrayInput $input, FakeOutput $output): ExitCode
    {
        return (new QueueRetryCommand($this->queue))->execute($input, $output);
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
