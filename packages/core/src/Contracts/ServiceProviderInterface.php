<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

/**
 * One subsystem's wiring. The two methods are separate phases: every provider
 * registers before any provider boots.
 */
interface ServiceProviderInterface
{
    /** Bindings only. Nothing here may resolve another provider's work. */
    public function register(ContainerInterface $container): void;

    /** Runs once every provider has registered, so the container is complete. */
    public function boot(ContainerInterface $container): void;
}
