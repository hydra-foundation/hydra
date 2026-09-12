<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * PSR-11 plus the three writes an application needs at wiring time. Kept
 * separate from any container library so the adapter is replaceable.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /** Resolved on first get(), reused thereafter. */
    public function singleton(string $abstract, callable|string $concrete): void;

    public function instance(string $abstract, object $instance): void;

    /** Whether the abstract resolves, which is not the same as it being bound. */
    public function bound(string $abstract): bool;
}
