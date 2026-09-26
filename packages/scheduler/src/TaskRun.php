<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeImmutable;

/** What one due task did on one tick. */
final readonly class TaskRun
{
    /**
     * @param class-string $class
     * @param int|null $items what a batch handled in total; null for a task
     * @param int|null $heldMinutes how long the other run had held the lock, when that is known
     * @param int|null $durationMs null for a run that was held rather than made
     * @param string|null $error the exception's class and message on one line; the trace is in the log
     */
    public function __construct(
        public string $class,
        public Outcome $outcome,
        public ?int $items = null,
        public ?int $heldMinutes = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?int $durationMs = null,
        public ?string $error = null,
    ) {}
}
