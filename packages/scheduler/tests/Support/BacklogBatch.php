<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Scheduler\Contracts\BatchInterface;

/** Hands out a fixed backlog in batches of $size. */
final class BacklogBatch implements BatchInterface
{
    public int $calls = 0;

    public function __construct(
        public int $remaining = 0,
        private readonly int $size = 20,
    ) {}

    public function batch(): int
    {
        $this->calls++;
        $handled = min($this->size, $this->remaining);
        $this->remaining -= $handled;

        return $handled;
    }
}
