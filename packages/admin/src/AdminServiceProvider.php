<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Responder;
use Hydra\Http\Router;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Binds the admin services and, at boot, loads the module screens into the
 * router as ordinary routes.
 */
final class AdminServiceProvider extends ServiceProvider
{
    /**
     * @param list<class-string<ModuleInterface>> $modules
     * @param list<class-string> $middleware
     */
    public function __construct(
        private readonly array $modules,
        private readonly string $prefix = '/admin',
        private readonly array $middleware = [],
    ) {}

    /**
     * The templates this package ships. Hand it to the view as a fallback and
     * the admin renders with no templates of your own; put a file of the same
     * name in the application's views and that one is used instead.
     */
    public static function views(): string
    {
        return dirname(__DIR__) . '/views';
    }

    /**
     * The stylesheet and script the shipped templates depend on, served by
     * {@see AssetController} at "{prefix}/assets/{name}". They live here for
     * the same reason the templates do: a field type the package adds arrives
     * styled, in every application, without anyone copying a file.
     */
    public static function assets(): string
    {
        return dirname(__DIR__) . '/assets';
    }

    public function register(ContainerInterface $container): void
    {
        $container->singleton(ModuleRegistry::class, function () use ($container) {
            return new ModuleRegistry($container, $this->modules, $this->prefix);
        });

        $container->singleton(Navigation::class, function () use ($container) {
            return new Navigation(
                $container->get(ModuleRegistry::class),
                $container->get(GateInterface::class),
            );
        });

        $container->singleton(Chrome::class, function () use ($container) {
            return new Chrome(
                $container->get(ModuleRegistry::class),
                $container->get(Navigation::class),
            );
        });

        $container->singleton(Renderer::class, function () use ($container) {
            return new Renderer(
                $container->get(Responder::class),
                $container->get(ViewInterface::class),
            );
        });

        // Registered rather than left to autowiring, and the reason is sharp
        // enough to be worth writing down: PHP-DI skips optional constructor
        // parameters entirely, so a dispatcher appended to the controller's
        // signature with a default would be null in every application,
        // including the ones that had bound one. The admin would announce
        // nothing and nothing would say why. Naming the arguments here is what
        // makes the event system actually reach the controller.
        $container->singleton(AdminController::class, function () use ($container) {
            // The dispatcher is OPTIONAL, the same way auth's is: the admin
            // depends on psr/event-dispatcher and never on hydrakit/event, so an
            // application with no dispatcher bound gets an admin that simply
            // emits no events.
            $events = $container->bound(EventDispatcherInterface::class)
                ? $container->get(EventDispatcherInterface::class)
                : null;

            return new AdminController(
                $container->get(ModuleRegistry::class),
                $container->get(Chrome::class),
                $container->get(Renderer::class),
                $container->get(GateInterface::class),
                $container->get(Responder::class),
                $container->get(Validator::class),
                $events,
                $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
                // Optional on the same terms, and with the same trap: an
                // application that binds a reader's zone and gets UTC anyway
                // would show every stored instant an hour or six out with
                // nothing on screen admitting it.
                $container->bound(TimezoneInterface::class)
                    ? $container->get(TimezoneInterface::class)
                    : new FixedTimezone,
            );
        });

        $container->singleton(AssetController::class, function () use ($container) {
            return new AssetController($container->get(Responder::class));
        });
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(Router::class)->loadRoutes($this->routes($container));
    }

    /** @return list<array<string, mixed>> */
    public function routes(ContainerInterface $container): array
    {
        return [
            ...$this->assetRoutes(),
            ...(new ModuleScanner)->scan(
                $container->get(ModuleRegistry::class)->all(),
                $this->prefix,
                $this->middleware,
            ),
        ];
    }

    /**
     * Ahead of the module routes, because the router takes the first match and
     * a module is free to call itself "assets". Deliberately without the
     * admin's middleware: a stylesheet is not a screen, and a sign-in page
     * that has to be styled before anyone has signed in cannot reach one that
     * is behind the gate.
     *
     * @return list<array<string, mixed>>
     */
    private function assetRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => rtrim($this->prefix, '/') . '/assets/{asset}',
            'handler' => [AssetController::class, 'show'],
            'middleware' => [],
            'name' => 'admin.asset',
        ]];
    }
}
