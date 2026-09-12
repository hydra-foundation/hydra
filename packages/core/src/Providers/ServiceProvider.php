<?php

declare(strict_types=1);

namespace Hydra\Core\Providers;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\ServiceProviderInterface;

/**
 * No-op base, so a provider overrides only the phase it takes part in.
 */
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void {}
    public function boot(ContainerInterface $container): void {}
}
