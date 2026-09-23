<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\Payload;
use Hydra\Queue\Testing\FakeQueue;
use Hydra\Queue\Testing\QueueContractTestCase;
use Hydra\Queue\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FakeQueue::class)]
#[CoversClass(Payload::class)]
#[CoversClass(QueueContractTestCase::class)]
final class FakeQueueContractTest extends QueueContractTestCase
{
    protected function queue(): QueueInterface
    {
        return new FakeQueue;
    }

    protected function job(): string
    {
        return RecordingJob::class;
    }
}
