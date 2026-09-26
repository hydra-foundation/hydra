<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use Hydra\Scheduler\Contracts\TaskInterface;

/** Keeps `scheduled_runs` to the last SCHEDULE_KEEP_DAYS. Schedule it daily. */
final class PruneScheduledRuns implements TaskInterface
{
    public function __construct(
        private readonly DatabaseRunLog $runs,
        private readonly SchedulerConfig $config,
    ) {}

    public function run(): void
    {
        $this->runs->prune($this->config->keepDays);
    }
}
