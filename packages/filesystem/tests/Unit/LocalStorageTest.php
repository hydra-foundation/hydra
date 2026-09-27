<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\LocalStorage;
use Hydra\Filesystem\Testing\StorageContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\StreamFactoryInterface;

#[CoversClass(LocalStorage::class)]
final class LocalStorageTest extends StorageContractTestCase
{
    private string $root;
    private ?LocalStorage $storage = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hydra-filesystem-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    protected function storage(): StorageInterface
    {
        return $this->storage ??= new LocalStorage($this->root, $this->streams());
    }

    protected function streams(): StreamFactoryInterface
    {
        return new Psr17Factory;
    }

    public function test_a_file_lands_under_the_root_at_its_key(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertFileExists($this->root . '/' . $key);
    }

    public function test_the_root_is_created_on_first_write(): void
    {
        $this->assertDirectoryDoesNotExist($this->root);

        $this->storage()->put('avatars', $this->png());

        $this->assertDirectoryExists($this->root . '/avatars');
    }

    public function test_no_temporary_file_is_left_beside_a_stored_one(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertSame([basename($key)], array_values(array_diff(scandir($this->root . '/avatars'), ['.', '..'])));
    }

    public function test_a_directory_is_not_a_file(): void
    {
        $this->storage()->put('avatars', $this->png());

        $this->assertFalse($this->storage()->exists('avatars'));
    }
}
