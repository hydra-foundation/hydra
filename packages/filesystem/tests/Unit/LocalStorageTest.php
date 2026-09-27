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

    public function test_a_trailing_slash_on_the_root_is_not_doubled(): void
    {
        $storage = new LocalStorage($this->root . '/', $this->streams());

        $key = $storage->put('avatars', $this->png());

        $this->assertFileExists($this->root . '/' . $key);
        $this->assertTrue($storage->exists($key));
    }

    public function test_the_web_server_can_read_what_is_stored(): void
    {
        // nginx serves the public disk as a user of its own, so a file and
        // the directories above it must be open to others for reading.
        $key = $this->storage()->put('blog/2026', $this->png());

        $this->assertSame(0o004, fileperms($this->root . '/' . $key) & 0o004);
        $this->assertSame(0o005, fileperms($this->root . '/blog') & 0o007);
        $this->assertSame(0o005, fileperms($this->root . '/blog/2026') & 0o007);
    }

    public function test_a_stream_already_read_to_its_end_is_stored_whole(): void
    {
        $stream = $this->png();
        $stream->getContents();

        $key = $this->storage()->put('avatars', $stream);

        $this->assertSame(base64_decode(self::PNG), (string) $this->storage()->read($key));
        $this->assertStringEndsWith('.png', $key);
    }

    public function test_a_file_larger_than_the_sniffed_head_is_stored_whole(): void
    {
        $bytes = base64_decode(self::PNG) . str_repeat("\x00", 200_000);

        $key = $this->storage()->put('avatars', $this->streams()->createStream($bytes));

        $this->assertSame(strlen($bytes), $this->storage()->size($key));
        $this->assertSame(md5($bytes), md5((string) $this->storage()->read($key)));
    }

    public function test_a_copy_that_fails_partway_leaves_nothing_behind(): void
    {
        $failing = new FailingStream(str_repeat('x', 70_000));

        try {
            $this->storage()->put('avatars', $failing);
            $this->fail('A failed read should have stopped the copy.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('The connection dropped.', $failure->getMessage());
        }

        $this->assertSame([], array_values(array_diff((array) scandir($this->root . '/avatars'), ['.', '..'])));
    }

    public function test_a_file_where_the_directory_should_be_is_an_error(): void
    {
        mkdir($this->root, 0o775, true);
        touch($this->root . '/avatars');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not create the directory');

        $this->storage()->put('avatars', $this->png());
    }
}
