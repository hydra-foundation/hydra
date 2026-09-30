<?php

declare(strict_types=1);

namespace Hydra\Queue;

use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Queue\Contracts\JobInterface;
use Hydra\Queue\Events\QueueChanged;
use Hydra\Scheduler\Contracts\BatchInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the queue from the scheduler: `$schedule->drain(Worker::class)`.
 * A job that finishes is deleted; one that throws waits out its backoff and
 * is tried again, until it is out of tries and moves to failed_jobs. Only
 * that last failure is reported: a retry that succeeds was never a fault.
 */
final class Worker implements BatchInterface
{
    public const DEFAULT_TRIES = 3;

    public const DEFAULT_BACKOFF = [10, 60, 300];

    public const DEFAULT_BATCH = 20;

    /**
     * @param list<int> $backoff seconds before each retry, in order; the last repeats
     * @param EventDispatcherInterface|null $events told with {@see QueueChanged} once per
     *        batch that handled a job, rather than once per job
     */
    public function __construct(
        private readonly DatabaseQueue $queue,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly int $tries = self::DEFAULT_TRIES,
        private readonly array $backoff = self::DEFAULT_BACKOFF,
        private readonly int $batchSize = self::DEFAULT_BATCH,
        private readonly ?ExceptionReporterInterface $reporter = null,
        private readonly ?EventDispatcherInterface $events = null,
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
        $failed = array_map($this->handle(...), $jobs);

        if ($jobs !== []) {
            $this->events?->dispatch(new QueueChanged(
                in_array(true, $failed, true) ? [QueueChanged::JOBS, QueueChanged::FAILED] : [QueueChanged::JOBS],
            ));
        }

        return count($jobs);
    }

    /** True when the job ran out of tries and moved to failed_jobs. */
    private function handle(ReservedJob $job): bool
    {
        try {
            $instance = $this->container->get($job->job);

            if (!$instance instanceof JobInterface) {
                throw new LogicException("The container built {$job->job} as something that is not a " . JobInterface::class . '.');
            }

            $instance->handle($job->payload());
        } catch (Throwable $e) {
            return $this->failed($job, $e);
        }

        $this->queue->delete($job);

        return false;
    }

    /** True when this was the last try. */
    private function failed(ReservedJob $job, Throwable $e): bool
    {
        $context = ['job' => $job->job, 'id' => $job->id, 'attempt' => $job->attempts, 'exception' => $e];

        if ($job->attempts >= $this->tries) {
            $this->queue->fail($job, $e);
            $this->logger->error(
                "Queued job {$job->job} failed on attempt {$job->attempts} of {$this->tries} and was moved to failed_jobs: {$e->getMessage()}",
                $context,
            );
            $this->report($e, ['job' => $job->job, 'id' => $job->id, 'attempt' => $job->attempts]);

            return true;
        }

        $delay = $this->backoff[min($job->attempts, count($this->backoff)) - 1];
        $this->queue->release($job, $delay);
        $this->logger->warning(
            "Queued job {$job->job} failed on attempt {$job->attempts} of {$this->tries} and will be tried again in {$delay} seconds: {$e->getMessage()}",
            $context,
        );

        return false;
    }

    /** @param array<string, scalar|null> $context */
    private function report(Throwable $e, array $context): void
    {
        try {
            $this->reporter?->report($e, $context);
        } catch (Throwable $failure) {
            $this->logger->warning('exception reporter failed: ' . $failure->getMessage(), ['exception' => $failure]);
        }
    }
}
