<?php

declare(strict_types=1);

namespace Hydra\Image;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Filesystem\FilesystemConfig;
use Hydra\Image\Console\VariantsCommand;
use Hydra\View\Contracts\ImagesInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills view's ImagesInterface with GD, keeping copies on the public disk
 * that hydrakit/filesystem configured: its directory, its URL, and the
 * document root its link lives in. The presets are the app's, given here.
 */
final class ImageServiceProvider extends ServiceProvider
{
    public function __construct(private readonly ImageOptions $options) {}

    public function register(ContainerInterface $container): void
    {
        $container->singleton(ImageOptions::class, fn (): ImageOptions => $this->options);

        $container->singleton(GdImages::class, function () use ($container): GdImages {
            $disk = $container->get(FilesystemConfig::class);

            return new GdImages(
                $this->options,
                dirname($disk->publicLink),
                $disk->publicRoot,
                $disk->publicUrl,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        });

        $container->singleton(ImagesInterface::class, fn (): ImagesInterface => $container->get(GdImages::class));

        $container->singleton(VariantsCommand::class, function () use ($container): VariantsCommand {
            $disk = $container->get(FilesystemConfig::class);

            return new VariantsCommand($container->get(GdImages::class), $this->options, dirname($disk->publicLink), $disk->publicRoot);
        });
    }
}
