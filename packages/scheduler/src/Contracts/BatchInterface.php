<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Contracts;

/**
 * Work done a capped batch at a time: find what is unprocessed, handle some of
 * it, and report how much. The scheduler calls it again until it reports none
 * or the entry's time budget is spent, so a backlog drains over as many runs
 * as it takes and a caught-up batch costs one query.
 */
interface BatchInterface
{
    /** How many items this call handled; 0 means there is nothing left. */
    public function batch(): int;
}
