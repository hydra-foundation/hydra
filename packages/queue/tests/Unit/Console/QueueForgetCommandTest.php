<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit\Console;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\Console\QueueForgetCommand;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass(QueueForgetCommand::class)]
final class QueueForgetCommandTest extends CommandContractTestCase
{
    private DatabaseQueue $queue;

    public static function commands(): iterable
    {
        yield 'queue:forget' => new QueueForgetCommand(self::emptyQueue());
    }

    protected function setUp(): void
    {
        $this->queue = self::emptyQueue();
    }

    public function test_one_failed_job_is_deleted_by_its_id(): void
    {
        $this->failJobs(2);
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->forget('1', $output));
        $output->assertSaid('Failed job 1 is deleted.');
        $this->assertSame([2], array_map(static fn ($j): int => $j->id, $this->queue->failed()));
        $this->assertSame([], $this->queue->reserve(10));
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

        $this->assertSame(ExitCode::Failure, $this->forget($id, $output));
        $output->assertError("No failed job has the id \"{$id}\". queue:failed lists them.");
        $this->assertCount(1, $this->queue->failed());
    }

    private function forget(string $id, FakeOutput $output): ExitCode
    {
        return (new QueueForgetCommand($this->queue))->execute(new ArrayInput(['id' => $id]), $output);
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
