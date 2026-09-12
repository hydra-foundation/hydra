<?php

declare(strict_types=1);

namespace Hydra\Session;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\Stores\NativeSessionStore;

/**
 * Wires the session package into an application.
 */
final class SessionServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Typed, immutable view of the SESSION_* settings, built once.
        $container->singleton(SessionConfig::class, function () use ($container) {
            return SessionConfig::fromEnvironment($container->get(Environment::class));
        });

        // The single store instance. Bound under its own class so the two
        // interface bindings below can share it.
        $container->singleton(NativeSessionStore::class, function () use ($container) {
            return new NativeSessionStore($container->get(SessionConfig::class));
        });

        // Both contracts resolve to that one instance — see the class docblock.
        $container->singleton(SessionInterface::class, fn () => $container->get(NativeSessionStore::class));
        $container->singleton(SessionLifecycleInterface::class, fn () => $container->get(NativeSessionStore::class));

        // StartSessionMiddleware is intentionally not bound here: its only
        // dependency is SessionLifecycleInterface (bound above), so the container
        // autowires it. This provider declares only the wiring that can't be
        // inferred — the shared-instance-behind-two-interfaces trick above.
    }
}
