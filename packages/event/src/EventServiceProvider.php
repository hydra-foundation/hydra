<?php

declare(strict_types=1);

namespace Hydra\Event;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Wires the event system into an application.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Bound under its own class-string too: listen() is not on the PSR
        // interface, so an app registering listeners needs the concrete type.
        $container->singleton(ListenerProvider::class, fn () => new ListenerProvider);

        // Same instance behind the interface, so a consumer depending only on
        // PSR-14 still sees the listeners the app registered.
        $container->singleton(
            ListenerProviderInterface::class,
            fn () => $container->get(ListenerProvider::class),
        );

        $container->singleton(EventDispatcherInterface::class, function () use ($container) {
            return new Dispatcher($container->get(ListenerProviderInterface::class));
        });
    }
}
