<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Scheduler\Contracts\TaskInterface;
use RuntimeException;

final class FailingTask implements TaskInterface
{
    public function run(): void
    {
        throw new RuntimeException('the disk is full');
    }
}
