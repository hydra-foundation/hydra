<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit\Console;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\Console\QueueFailedCommand;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(QueueFailedCommand::class)]
final class QueueFailedCommandTest extends CommandContractTestCase
{
    private DatabaseQueue $queue;

    public static function commands(): iterable
    {
        yield 'queue:failed' => new QueueFailedCommand(self::emptyQueue());
    }

    protected function setUp(): void
    {
        $this->queue = self::emptyQueue();
    }

    public function test_an_empty_list_says_so(): void
    {
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, (new QueueFailedCommand($this->queue))->execute(new ArrayInput, $output));
        $output->assertSaid('No failed jobs.');
        $this->assertSame([], $output->tables());
    }

    public function test_each_failed_job_is_a_row_with_the_reason_cut_short(): void
    {
        $this->failWith('the mail server is down');
        $this->failWith(str_repeat('x', 200));
        $output = new FakeOutput;

        $this->assertSame(ExitCode::Success, (new QueueFailedCommand($this->queue))->execute(new ArrayInput, $output));

        $table = $output->tables()[0];
        $this->assertSame(['ID', 'Job', 'Failed (UTC)', 'Reason'], $table['headers']);
        $this->assertSame(['2', RecordingJob::class, '2026-09-23 04:00:00'], array_slice($table['rows'][0], 0, 3));
        $this->assertSame(120, mb_strlen($table['rows'][0][3]));
        $this->assertStringEndsWith('x…', $table['rows'][0][3]);
        $this->assertSame(['1', RecordingJob::class, '2026-09-23 04:00:00', RuntimeException::class . ': the mail server is down'], $table['rows'][1]);
        $output->assertSaid('queue:retry <id>');
    }

    private function failWith(string $message): void
    {
        $this->queue->push(RecordingJob::class);
        [$job] = $this->queue->reserve(1);
        $this->queue->fail($job, new RuntimeException($message));
    }

    private static function emptyQueue(): DatabaseQueue
    {
        $db = FakeConnection::inMemory();
        QueueTables::create($db);

        return new DatabaseQueue($db, new FrozenClock('2026-09-23T04:00:00+00:00'), 'sqlite');
    }
}
