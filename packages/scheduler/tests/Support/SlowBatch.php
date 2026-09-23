<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Scheduler\Contracts\BatchInterface;

/** Never runs out of work, and every batch takes ten minutes of the clock. */
final class SlowBatch implements BatchInterface
{
    public int $calls = 0;

    public function __construct(private readonly FrozenClock $clock) {}

    public function batch(): int
    {
        $this->calls++;
        $this->clock->advance('+10 minutes');

        return 20;
    }
}
