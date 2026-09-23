<?php

declare(strict_types=1);

namespace Hydra\Queue\Testing;

use Hydra\Queue\Contracts\JobInterface;
use Hydra\Queue\Contracts\QueueInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * What every queue refuses, whether it stores the job or only records it.
 * Extend it with the queue under test.
 */
abstract class QueueContractTestCase extends TestCase
{
    abstract protected function queue(): QueueInterface;

    /** @return class-string<JobInterface> a job the queue under test may push */
    abstract protected function job(): string;

    public function test_it_takes_a_job_with_a_payload_of_plain_data(): void
    {
        $this->queue()->push($this->job(), ['id' => 7, 'to' => ['ada@example.com'], 'ratio' => 1.0, 'note' => null], delay: 30);

        $this->addToAssertionCount(1);
    }

    public function test_a_class_that_is_not_a_job_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement');

        /** @phpstan-ignore argument.type */
        $this->queue()->push(stdClass::class);
    }

    public function test_an_object_in_the_payload_is_refused_by_its_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"user.0" is a stdClass');

        $this->queue()->push($this->job(), ['user' => [new stdClass]]);
    }

    public function test_a_payload_json_cannot_hold_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be stored as JSON');

        $this->queue()->push($this->job(), ['name' => "\xB1\x31"]);
    }

    public function test_a_negative_delay_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->queue()->push($this->job(), delay: -1);
    }
}
