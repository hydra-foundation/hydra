<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

/**
 * The request lifecycle, split either side of the response so an application
 * can do work after the client has been answered.
 */
interface KernelInterface
{
    /** Builds the request, dispatches it, and emits the response itself. */
    public function handle(): void;

    /** Runs after the response is flushed, so its cost is off the client's clock. */
    public function terminate(): void;
}
