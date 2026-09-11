<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

/**
 * Kernel interface
 */
interface KernelInterface
{
    /**
     * Handle the incoming request lifecycle: build the request, dispatch it,
     * and emit the response. Nothing is returned — the response is sent.
     */
    public function handle(): void;

    /**
     * Post-response clean-up
     */
    public function terminate(): void;
}
