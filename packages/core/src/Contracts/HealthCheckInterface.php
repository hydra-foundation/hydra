<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Throwable;

/**
 * One dependency the application cannot serve without, asked whether it
 * answers. Run on every probe of the health endpoint, so it should cost a
 * round trip and no more.
 */
interface HealthCheckInterface
{
    /** A short stable key for the endpoint's body: `database`, `cache`. */
    public function name(): string;

    /**
     * Returns when the dependency answers.
     *
     * @throws Throwable when it does not; the message goes to the log, never to the endpoint
     */
    public function check(): void;
}
