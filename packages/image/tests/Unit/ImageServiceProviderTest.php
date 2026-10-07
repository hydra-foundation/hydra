<?php

declare(strict_types=1);

namespace Hydra\Image\Tests\Unit;

use Hydra\Core\Testing\FakeContainer;
use Hydra\Filesystem\FilesystemConfig;
use Hydra\Image\Console\VariantsCommand;
use Hydra\Image\GdImages;
use Hydra\Image\ImageOptions;
use Hydra\Image\ImageServiceProvider;
use Hydra\Image\Preset;
use Hydra\View\Contracts\ImagesInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** What a view needs for $this->image(), on the public disk filesystem set up. */
#[CoversClass(ImageServiceProvider::class)]
final class ImageServiceProviderTest extends TestCase
{
    public function test_images_resolve_once_on_the_public_disk_with_the_apps_presets(): void
    {
        $base = sys_get_temp_dir() . '/hydra-image-provider-' . bin2hex(random_bytes(6));
        mkdir($base . '/public/images', 0o777, true);
        mkdir($base . '/storage/public', 0o777, true);
        $image = imagecreatetruecolor(400, 200);
        $this->assertNotFalse($image);
        imagejpeg($image, $base . '/public/images/a.jpg');

        $container = new FakeContainer;
        $container->instance(FilesystemConfig::class, new FilesystemConfig(
            privateRoot: $base . '/storage/uploads',
            publicRoot: $base . '/storage/public',
            publicLink: $base . '/public/storage',
            publicUrl: '/files',
        ));
        $options = new ImageOptions(['card' => Preset::widths(200)]);
        (new ImageServiceProvider($options))->register($container);

        $images = $container->get(ImagesInterface::class);
        $variant = $images->variants('/images/a.jpg', 'card')[0];

        $this->assertInstanceOf(GdImages::class, $images);
        $this->assertSame($images, $container->get(ImagesInterface::class));
        $this->assertSame($options, $container->get(ImageOptions::class));
        $this->assertStringStartsWith('/files/variants/card/', $variant->url);
        $this->assertFileExists($base . '/storage/public/' . substr($variant->url, strlen('/files/')));
        $this->assertInstanceOf(VariantsCommand::class, $container->get(VariantsCommand::class));

        exec('rm -rf ' . escapeshellarg($base));
    }
}
