<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Filesystem\Console\StorageLinkCommand;
use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Disks;
use Hydra\Filesystem\FilesystemConfig;
use Hydra\Filesystem\FilesystemServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;

#[CoversClass(FilesystemServiceProvider::class)]
#[CoversClass(FilesystemConfig::class)]
final class FilesystemServiceProviderTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/hydra-fs-provider-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Environment exports what it reads from a .env into the process, and
        // the default-URL case must not inherit the override from another.
        putenv('FILESYSTEM_PUBLIC_URL');
        unset($_ENV['FILESYSTEM_PUBLIC_URL']);
        TemporaryDirectory::remove($this->base);
    }

    public function test_the_default_storage_is_the_private_disk(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Disks::class)->private(), $container->get(StorageInterface::class));
    }

    public function test_the_public_storage_is_the_public_disk(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Disks::class)->public(), $container->get(PublicStorageInterface::class));
    }

    public function test_the_disks_are_rooted_under_the_storage_path(): void
    {
        $config = $this->container()->get(FilesystemConfig::class);

        $this->assertSame($this->base . '/storage/uploads', $config->privateRoot);
        $this->assertSame($this->base . '/storage/public', $config->publicRoot);
        $this->assertSame($this->base . '/public/storage', $config->publicLink);
        $this->assertSame('/storage', $config->publicUrl);
    }

    public function test_a_file_put_on_each_disk_lands_in_its_own_root(): void
    {
        $disks = $this->container()->get(Disks::class);

        $private = $disks->private()->put('avatars', (new Psr17Factory)->createStream('a'));
        $public = $disks->public()->put('blog', (new Psr17Factory)->createStream('b'));

        $this->assertFileExists($this->base . '/storage/uploads/' . $private);
        $this->assertFileExists($this->base . '/storage/public/' . $public);
        $this->assertSame('/storage/' . $public, $disks->public()->url($public));
    }

    public function test_trailing_slashes_on_the_paths_are_not_doubled(): void
    {
        $config = FilesystemConfig::fromEnvironment(new Environment(__DIR__), '/srv/app/storage/', '/srv/app/public/');

        $this->assertSame('/srv/app/storage/uploads', $config->privateRoot);
        $this->assertSame('/srv/app/storage/public', $config->publicRoot);
        $this->assertSame('/srv/app/public/storage', $config->publicLink);
    }

    public function test_the_public_url_can_point_elsewhere(): void
    {
        $env = $this->base . '/env';
        mkdir($env, 0o775, true);
        file_put_contents($env . '/.env', "FILESYSTEM_PUBLIC_URL=https://cdn.example.com/files\n");

        $config = $this->container(new Environment($env))->get(FilesystemConfig::class);

        $this->assertSame('https://cdn.example.com/files', $config->publicUrl);
    }

    public function test_the_link_command_links_the_configured_paths(): void
    {
        $this->assertInstanceOf(StorageLinkCommand::class, $this->container()->get(StorageLinkCommand::class));
    }

    private function container(?Environment $env = null): ContainerInterface
    {
        $container = new FakeContainer([
            Environment::class => $env ?? new Environment(__DIR__),
            StreamFactoryInterface::class => new Psr17Factory,
        ]);

        (new FilesystemServiceProvider(
            storagePath: $this->base . '/storage',
            publicPath: $this->base . '/public',
        ))->register($container);

        return $container;
    }
}
