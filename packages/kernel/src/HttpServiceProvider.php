<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\Emitter;
use Hydra\Http\HttpKernel;
use Hydra\Http\Pipeline;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\RouteCache;
use Hydra\Http\RouteScanner;
use Hydra\Http\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP service provider
 *
 * Binds the framework's HTTP plumbing
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $controllers,
        private readonly array $middleware,
        private readonly bool $routeCacheEnabled,
        private readonly string $routeCachePath,
    ) {}

    public function register(ContainerInterface $container): void
    {
        $container->singleton(EmitterInterface::class, fn () => new Emitter);

        $container->singleton(Responder::class, function () use ($container) {
            return new Responder(
                $container->get(ResponseFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
            );
        });

        $container->singleton(ErrorRendererInterface::class, function () use ($container) {
            return new PlainTextErrorRenderer($container->get(Responder::class));
        });

        $container->singleton(RouteCache::class, fn () => new RouteCache($this->routeCachePath, $this->controllers));

        $container->singleton(Router::class, function () use ($container) {
            $router = new Router($container);
            $router->loadRoutes($this->compileRoutes($container));
            return $router;
        });

        $container->singleton(RequestHandlerInterface::class, function () use ($container) {
            $middleware = array_map(
                fn (string $class) => $container->get($class),
                $this->middleware,
            );

            return new Pipeline($middleware, $container->get(Router::class));
        });

        $container->singleton(KernelInterface::class, function () use ($container) {
            return new HttpKernel(
                $container->get(ServerRequestProviderInterface::class),
                $container->get(RequestHandlerInterface::class),
                $container->get(EmitterInterface::class),
            );
        });
    }

    /**
     * The compiled route definitions for the Router
     */
    private function compileRoutes(ContainerInterface $container): array
    {
        $scan = fn (): array => (new RouteScanner)->scan($this->controllers);

        if (!$this->routeCacheEnabled) {
            return $scan();
        }

        return $container->get(RouteCache::class)->load() ?? $scan();
    }
}
