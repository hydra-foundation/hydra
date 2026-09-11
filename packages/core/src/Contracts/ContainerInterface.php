<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Container interface
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind an abstract to a concrete implementation, resolved once and reused.
     */
    public function singleton(string $abstract, callable|string $concrete): void;

    /**
     * Register an already-constructed instance under an abstract.
     */
    public function instance(string $abstract, object $instance): void;

    /**
     * Whether the container can RESOLVE the abstract
     */
    public function bound(string $abstract): bool;
}
