<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateInterval;
use DateTimeImmutable;
use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Scheduler\Contracts\BatchInterface;
use Hydra\Scheduler\Contracts\RunLogInterface;
use Hydra\Scheduler\Contracts\TaskInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One tick: every task due this minute, in the order it was declared, one after
 * another. A long batch holds up what is declared after it for as long as it
 * runs, which is what declaring it last is for.
 */
final class Runner
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ContainerInterface $container,
        private readonly ClockInterface $clock,
        private readonly LockDirectory $locks,
        private readonly LoggerInterface $logger,
        private readonly ?ExceptionReporterInterface $reporter = null,
        private readonly RunLogInterface $runs = new NullRunLog,
    ) {}

    /** @return list<TaskRun> */
    public function run(): array
    {
        return array_map($this->runAndRecord(...), $this->schedule->due($this->clock->now()));
    }

    private function runAndRecord(ScheduledTask $task): TaskRun
    {
        $run = $this->runOne($task);

        try {
            $this->runs->record($run);
        } catch (Throwable $e) {
            $this->logger->warning("Could not record the run of {$task->class}: {$e->getMessage()}", ['exception' => $e]);
        }

        return $run;
    }

    private function runOne(ScheduledTask $task): TaskRun
    {
        $started = $this->clock->now();
        $lock = $this->locks->acquire($task->class, $started);

        if ($lock === null) {
            return $this->held($task, $started);
        }

        try {
            if ($task->batched) {
                $items = $this->drain($task);

                return new TaskRun($task->class, Outcome::Ran, $items, startedAt: $started, durationMs: $this->since($started));
            }

            $this->resolve($task, TaskInterface::class)->run();

            return new TaskRun($task->class, Outcome::Ran, startedAt: $started, durationMs: $this->since($started));
        } catch (Throwable $e) {
            $this->logger->error("Scheduled task {$task->class} failed: {$e->getMessage()}", ['exception' => $e]);
            $this->report($e, $task);

            return new TaskRun(
                $task->class,
                Outcome::Failed,
                startedAt: $started,
                durationMs: $this->since($started),
                error: $e::class . ': ' . (string) preg_replace('/\s*\R\s*/', ' ', $e->getMessage()),
            );
        } finally {
            $lock->release();
        }
    }

    private function since(DateTimeImmutable $started): int
    {
        $elapsed = (float) $this->clock->now()->format('U.u') - (float) $started->format('U.u');

        return max(0, (int) round($elapsed * 1000));
    }

    private function report(Throwable $e, ScheduledTask $task): void
    {
        try {
            $this->reporter?->report($e, ['task' => $task->class]);
        } catch (Throwable $failure) {
            $this->logger->warning('exception reporter failed: ' . $failure->getMessage(), ['exception' => $failure]);
        }
    }

    private function drain(ScheduledTask $task): int
    {
        $batch = $this->resolve($task, BatchInterface::class);
        $budget = $task->budgetMinutes();
        $deadline = $budget === null ? null : $this->clock->now()->add(new DateInterval("PT{$budget}M"));
        $total = 0;

        do {
            $handled = $batch->batch();
            $total += max(0, $handled);
        } while ($handled > 0 && ($deadline === null || $this->clock->now() < $deadline));

        return $total;
    }

    private function held(ScheduledTask $task, DateTimeImmutable $now): TaskRun
    {
        $started = $this->locks->startedAt($task->class);
        $minutes = $started === null ? null : intdiv($now->getTimestamp() - $started, 60);

        if ($minutes !== null && $minutes >= $task->warnMinutes()) {
            $this->logger->warning(
                "Scheduled task {$task->class} has held its lock for {$minutes} minutes and may be hung; it is not being run again beside it.",
                ['task' => $task->class, 'minutes' => $minutes],
            );
        }

        return new TaskRun($task->class, Outcome::Held, heldMinutes: $minutes, startedAt: $now);
    }

    /**
     * @template T of object
     * @param class-string<T> $contract
     * @return T
     */
    private function resolve(ScheduledTask $task, string $contract): object
    {
        $instance = $this->container->get($task->class);

        return $instance instanceof $contract
            ? $instance
            : throw new LogicException("The container built {$task->class} as something that is not a {$contract}.");
    }
}
