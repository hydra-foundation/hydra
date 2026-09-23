<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

/**
 * A task's lock while this process holds it. Dropping the object releases the
 * lock as well, since its handle closes with it: a caller that means to hold
 * a lock has to keep the reference.
 */
final class HeldLock
{
    /** @param resource $handle */
    public function __construct(private $handle) {}

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
