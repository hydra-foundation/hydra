<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use Hydra\Scheduler\Contracts\RunLogInterface;

/** Keeps nothing: the default, for an application with nowhere to put runs. */
final class NullRunLog implements RunLogInterface
{
    public function record(TaskRun $run): void {}
}
