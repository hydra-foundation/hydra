<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Scheduler\Contracts\RunLogInterface;
use Hydra\Scheduler\TaskRun;

final class RecordingRunLog implements RunLogInterface
{
    /** @var list<TaskRun> */
    public array $runs = [];

    public function record(TaskRun $run): void
    {
        $this->runs[] = $run;
    }
}
