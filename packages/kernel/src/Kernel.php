<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Event\EventServiceProvider;
use Hydra\Session\SessionServiceProvider;

/**
 * The framework's default composition root: the provider stack that is
 * identical in every Hydra app, so an AppServiceProvider holds only policy.
 */
final class Kernel
{
    public static function application(ContainerInterface $container, Environment $environment): Application
    {
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, $environment);
        return (new Application($container))
            ->register(new SessionServiceProvider)
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider);
    }
}
