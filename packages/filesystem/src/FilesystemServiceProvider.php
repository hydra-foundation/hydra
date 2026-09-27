<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Filesystem\Console\StorageLinkCommand;
use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Contracts\StorageInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Wires the two disks into an application. A stream factory is expected to be
 * bound already; the nyholm package binds one.
 *
 * StorageInterface is the private disk. Code that asks for "storage" without
 * saying which gets the one nothing can reach from a browser, and has to ask
 * for PublicStorageInterface by name to publish anything.
 */
final class FilesystemServiceProvider extends ServiceProvider
{
    /**
     * @param string $storagePath the application's storage directory
     * @param string $publicPath  the web server's document root
     */
    public function __construct(
        private readonly string $storagePath,
        private readonly string $publicPath,
    ) {}

    public function register(ContainerInterface $container): void
    {
        $container->singleton(FilesystemConfig::class, function () use ($container) {
            return FilesystemConfig::fromEnvironment(
                $container->get(Environment::class),
                $this->storagePath,
                $this->publicPath,
            );
        });

        $container->singleton(Disks::class, function () use ($container) {
            $config = $container->get(FilesystemConfig::class);
            $streams = $container->get(StreamFactoryInterface::class);

            return new Disks(
                new LocalStorage($config->privateRoot, $streams),
                new LocalPublicStorage($config->publicRoot, $config->publicUrl, $streams),
            );
        });

        $container->singleton(StorageInterface::class, fn () => $container->get(Disks::class)->private());
        $container->singleton(PublicStorageInterface::class, fn () => $container->get(Disks::class)->public());

        $container->singleton(StorageLinkCommand::class, function () use ($container) {
            $config = $container->get(FilesystemConfig::class);

            return new StorageLinkCommand($config->publicRoot, $config->publicLink);
        });
    }
}
