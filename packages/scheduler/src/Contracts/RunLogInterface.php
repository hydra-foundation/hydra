<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Contracts;

use Hydra\Scheduler\TaskRun;

/** Where each run a tick makes is kept, so what the scheduler did outlives the tick. */
interface RunLogInterface
{
    public function record(TaskRun $run): void;
}
