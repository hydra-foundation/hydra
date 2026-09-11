<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Session\Contracts\SessionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Auth service provider
 *
 * Wires the auth package into an application
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Typed, immutable view of the AUTH_* settings (the bcrypt work factor).
        $container->singleton(AuthConfig::class, function () use ($container) {
            return AuthConfig::fromEnvironment($container->get(Environment::class));
        });

        // The default password hasher, bound behind its interface so an app can
        // swap it (a pepper, a legacy-hash bridge) without touching the guard.
        $container->singleton(HasherInterface::class, function () use ($container) {
            return new NativeHasher($container->get(AuthConfig::class));
        });

        // The session-backed guard, shared for the request so the middleware and
        // controllers see one consistent authentication state (and its per-request
        // user cache). It pulls the app-supplied UserProviderInterface — which
        // this provider intentionally does NOT bind.
        $container->singleton(GuardInterface::class, function () use ($container) {
            // The event dispatcher is OPTIONAL: auth depends on the PSR interface,
            // not on hydrakit/event. When an app has bound a dispatcher the guard
            // announces its lifecycle through it; when it hasn't, the guard gets
            // null and simply emits no events. Never a hard dependency.
            $events = $container->bound(EventDispatcherInterface::class)
                ? $container->get(EventDispatcherInterface::class)
                : null;

            return new SessionGuard(
                $container->get(SessionInterface::class),
                $container->get(UserProviderInterface::class),
                $container->get(HasherInterface::class),
                $events,
            );
        });

        // AuthenticateMiddleware is left to container autowiring: its only
        // dependency is GuardInterface (bound above). This provider declares only
        // the wiring that can't be inferred.
    }
}
