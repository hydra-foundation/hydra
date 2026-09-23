<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

enum Outcome: string
{
    case Ran = 'ran';

    /** Another run still held the lock, so this one was skipped. */
    case Held = 'held';

    case Failed = 'failed';
}
