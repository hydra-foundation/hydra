<?php

declare(strict_types=1);

namespace Hydra\Authorization;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Authorization service provider
 *
 * Wires the authorization package into an application
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(GateInterface::class, function () use ($container) {
            return new Gate($container, $container->get(GuardInterface::class));
        });
    }
}
