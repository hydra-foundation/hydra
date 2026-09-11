<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Responder;
use Hydra\Http\Router;
use Hydra\View\Contracts\ViewInterface;

/**
 * Admin service provider
 *
 * Binds the admin services and, at boot, loads the module screens into the
 * router as ordinary routes.
 */
final class AdminServiceProvider extends ServiceProvider
{
    /**
     * @param list<class-string<ModuleInterface>> $modules
     * @param list<class-string>                  $middleware
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
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(Router::class)->loadRoutes($this->routes($container));
    }

    public function routes(ContainerInterface $container): array
    {
        return (new ModuleScanner)->scan(
            $container->get(ModuleRegistry::class)->all(),
            $this->prefix,
            $this->middleware,
        );
    }
}
