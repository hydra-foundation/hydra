<?php

declare(strict_types=1);

namespace Hydra\Queue;

use Hydra\Queue\Contracts\JobInterface;
use Hydra\Scheduler\Contracts\BatchInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the queue from the scheduler: `$schedule->drain(Worker::class)`.
 * A job that finishes is deleted; one that throws waits out its backoff and
 * is tried again, until it is out of tries and moves to failed_jobs.
 */
final class Worker implements BatchInterface
{
    public const DEFAULT_TRIES = 3;

    public const DEFAULT_BACKOFF = [10, 60, 300];

    public const DEFAULT_BATCH = 20;

    /**
     * @param list<int> $backoff seconds before each retry, in order; the last repeats
     */
    public function __construct(
        private readonly DatabaseQueue $queue,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly int $tries = self::DEFAULT_TRIES,
        private readonly array $backoff = self::DEFAULT_BACKOFF,
        private readonly int $batchSize = self::DEFAULT_BATCH,
    ) {
        if ($tries < 1 || $batchSize < 1) {
            throw new InvalidArgumentException('A worker needs at least one try and a batch of at least one job.');
        }

        if ($backoff === [] || min($backoff) < 0) {
            throw new InvalidArgumentException('The backoff needs at least one delay, and none below zero.');
        }
    }

    public function batch(): int
    {
        $jobs = $this->queue->reserve($this->batchSize);
        array_map($this->handle(...), $jobs);

        return count($jobs);
    }

    private function handle(ReservedJob $job): void
    {
        try {
            $instance = $this->container->get($job->job);

            if (!$instance instanceof JobInterface) {
                throw new LogicException("The container built {$job->job} as something that is not a " . JobInterface::class . '.');
            }

            $instance->handle($job->payload());
        } catch (Throwable $e) {
            $this->failed($job, $e);

            return;
        }

        $this->queue->delete($job);
    }

    private function failed(ReservedJob $job, Throwable $e): void
    {
        $context = ['job' => $job->job, 'id' => $job->id, 'attempt' => $job->attempts, 'exception' => $e];

        if ($job->attempts >= $this->tries) {
            $this->queue->fail($job, $e);
            $this->logger->error(
                "Queued job {$job->job} failed on attempt {$job->attempts} of {$this->tries} and was moved to failed_jobs: {$e->getMessage()}",
                $context,
            );

            return;
        }

        $delay = $this->backoff[min($job->attempts, count($this->backoff)) - 1];
        $this->queue->release($job, $delay);
        $this->logger->warning(
            "Queued job {$job->job} failed on attempt {$job->attempts} of {$this->tries} and will be tried again in {$delay} seconds: {$e->getMessage()}",
            $context,
        );
    }
}
