<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hydra\Scheduler\Contracts\BatchInterface;
use Hydra\Scheduler\Contracts\TaskInterface;
use InvalidArgumentException;

/**
 * What the application runs and when. Times are read in the schedule's zone,
 * so dailyAt('04:00') means four in the morning where the application is,
 * whatever zone the clock reports in.
 */
final class Schedule
{
    /** @var array<class-string, ScheduledTask> */
    private array $tasks = [];

    public function __construct(private readonly DateTimeZone $timezone) {}

    /** @param class-string<TaskInterface> $class */
    public function run(string $class): ScheduledTask
    {
        return $this->add($class, TaskInterface::class, false);
    }

    /** @param class-string<BatchInterface> $class */
    public function drain(string $class): ScheduledTask
    {
        return $this->add($class, BatchInterface::class, true);
    }

    /** @return list<ScheduledTask> in the order they were declared */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    /** @return list<ScheduledTask> */
    public function due(DateTimeInterface $now): array
    {
        $local = DateTimeImmutable::createFromInterface($now)->setTimezone($this->timezone);

        return array_values(array_filter(
            $this->tasks,
            static fn (ScheduledTask $task): bool => $task->isDue($local),
        ));
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /** @param class-string $contract */
    private function add(string $class, string $contract, bool $batched): ScheduledTask
    {
        if (!is_a($class, $contract, true)) {
            throw new InvalidArgumentException("{$class} does not implement {$contract}.");
        }

        // The class is the lock's name, so a second entry would share the first's lock.
        if (isset($this->tasks[$class])) {
            throw new InvalidArgumentException("{$class} is already scheduled; one entry per class.");
        }

        return $this->tasks[$class] = new ScheduledTask($class, $batched);
    }
}
