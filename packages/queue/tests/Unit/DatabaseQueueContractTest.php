<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Payload;
use Hydra\Queue\Testing\QueueContractTestCase;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DatabaseQueue::class)]
#[CoversClass(Payload::class)]
#[CoversClass(QueueContractTestCase::class)]
final class DatabaseQueueContractTest extends QueueContractTestCase
{
    protected function queue(): QueueInterface
    {
        $db = FakeConnection::inMemory();
        QueueTables::create($db);

        return new DatabaseQueue($db, new FrozenClock, 'sqlite');
    }

    protected function job(): string
    {
        return RecordingJob::class;
    }
}
