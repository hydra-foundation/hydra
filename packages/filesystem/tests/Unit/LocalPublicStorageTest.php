<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\LocalPublicStorage;
use Hydra\Filesystem\StoredFile;
use Hydra\Filesystem\Testing\StorageContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\StreamFactoryInterface;

#[CoversClass(LocalPublicStorage::class)]
#[CoversClass(StoredFile::class)]
final class LocalPublicStorageTest extends StorageContractTestCase
{
    private string $root;
    private ?LocalPublicStorage $storage = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hydra-public-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    protected function storage(): LocalPublicStorage
    {
        return $this->storage ??= new LocalPublicStorage($this->root, '/storage', $this->streams());
    }

    protected function streams(): StreamFactoryInterface
    {
        return new Psr17Factory;
    }

    public function test_a_stored_file_has_a_url_under_the_base(): void
    {
        $key = $this->storage()->put('blog', $this->png());

        $this->assertSame('/storage/' . $key, $this->storage()->url($key));
    }

    public function test_a_trailing_slash_on_the_base_is_not_doubled(): void
    {
        $storage = new LocalPublicStorage($this->root, 'https://cdn.example.com/files/', $this->streams());

        $this->assertSame('https://cdn.example.com/files/blog/a.png', $storage->url('blog/a.png'));
    }

    public function test_a_url_is_never_built_for_a_key_outside_the_disk(): void
    {
        $this->expectException(InvalidKey::class);

        $this->storage()->url('../.env');
    }

    public function test_it_is_still_a_storage(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage());
    }
}
