<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Scheduler\Contracts\TaskInterface;

final class NoteTask implements TaskInterface
{
    public int $runs = 0;

    public function run(): void
    {
        $this->runs++;
    }
}
