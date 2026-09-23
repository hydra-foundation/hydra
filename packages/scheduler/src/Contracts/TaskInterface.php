<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Contracts;

/** Work the scheduler runs once each time it is due. */
interface TaskInterface
{
    public function run(): void;
}
