<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

/** What one due task did on one tick. */
final readonly class TaskRun
{
    /**
     * @param class-string $class
     * @param int|null $items what a batch handled in total; null for a task
     * @param int|null $heldMinutes how long the other run had held the lock, when that is known
     */
    public function __construct(
        public string $class,
        public Outcome $outcome,
        public ?int $items = null,
        public ?int $heldMinutes = null,
    ) {}
}
