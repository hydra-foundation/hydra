<?php

declare(strict_types=1);

namespace Hydra\Event;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Event service provider
 *
 * Wires the event system into an application.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // The one shared registry, bound under its own class-string so the app
        // can resolve it to call listen().
        $container->singleton(ListenerProvider::class, fn() => new ListenerProvider);

        // The PSR-14 read interface points at that same instance, so a consumer
        // that depends only on the interface still sees the app's listeners.
        $container->singleton(
            ListenerProviderInterface::class,
            fn() => $container->get(ListenerProvider::class),
        );

        // The dispatcher over that registry. Subsystems (like auth) depend on the
        // PSR interface, never on this concrete class.
        $container->singleton(EventDispatcherInterface::class, function () use ($container) {
            return new Dispatcher($container->get(ListenerProviderInterface::class));
        });
    }
}
