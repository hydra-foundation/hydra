<?php

declare(strict_types=1);

namespace Hydra\Queue\Testing;

use Hydra\Queue\Contracts\JobInterface;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\Payload;
use PHPUnit\Framework\Assert;

/**
 * A queue that records what was pushed and runs none of it. A push it accepts
 * is one the database queue would accept, and the payload it keeps is the one
 * a job would be handed, after the trip through JSON.
 */
final class FakeQueue implements QueueInterface
{
    /** @var list<QueuedJob> */
    private array $pushed = [];

    public function push(string $job, array $payload = [], int $delay = 0): void
    {
        Payload::checkJob($job, $delay);

        $this->pushed[] = new QueuedJob($job, Payload::decode(Payload::encode($payload)), $delay);
    }

    /**
     * @param class-string<JobInterface>|null $job
     * @param (callable(array<array-key, mixed>, QueuedJob): bool)|null $matching
     * @return list<QueuedJob>
     */
    public function pushed(?string $job = null, ?callable $matching = null): array
    {
        return array_values(array_filter(
            $this->pushed,
            static fn (QueuedJob $queued): bool => ($job === null || $queued->job === $job)
                && ($matching === null || $matching($queued->payload, $queued)),
        ));
    }

    /**
     * At least one push of $job matched, or exactly $times did when given.
     *
     * @param class-string<JobInterface> $job
     * @param (callable(array<array-key, mixed>, QueuedJob): bool)|null $matching
     */
    public function assertQueued(string $job, ?callable $matching = null, ?int $times = null): void
    {
        $count = count($this->pushed($job, $matching));

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "No matching {$job} was queued.");

            return;
        }

        Assert::assertSame($times, $count, "Expected {$times} matching {$job}; {$count} were queued.");
    }

    /**
     * @param class-string<JobInterface> $job
     * @param (callable(array<array-key, mixed>, QueuedJob): bool)|null $matching
     */
    public function assertNotQueued(string $job, ?callable $matching = null): void
    {
        $count = count($this->pushed($job, $matching));

        Assert::assertSame(0, $count, "{$count} matching {$job} were queued.");
    }

    public function assertNothingQueued(): void
    {
        Assert::assertSame([], $this->pushed, count($this->pushed) . ' jobs were queued.');
    }
}
