<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

/**
 * Service provider interface
 */
interface ServiceProviderInterface
{
    /**
     * Register bindings into container
     */
    public function register(ContainerInterface $container): void;

    /**
     * Boot any application services
     */
    public function boot(ContainerInterface $container): void;
}
