<?php

declare(strict_types=1);

namespace Hydra\Nyholm;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Fills Hydra's PSR-7/17 seams with nyholm.
 */
final class NyholmServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // nyholm's Psr17Factory implements every PSR-17 factory interface.
        $container->singleton(Psr17Factory::class, fn () => new Psr17Factory);
        $container->singleton(ResponseFactoryInterface::class, fn () => $container->get(Psr17Factory::class));
        $container->singleton(StreamFactoryInterface::class, fn () => $container->get(Psr17Factory::class));

        $container->singleton(ServerRequestProviderInterface::class, function () use ($container) {
            return NyholmRequestProvider::create($container->get(Psr17Factory::class));
        });
    }
}
