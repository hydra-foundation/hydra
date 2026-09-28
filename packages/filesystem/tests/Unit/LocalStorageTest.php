<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\LocalStorage;
use Hydra\Filesystem\Testing\StorageContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresFunction;
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
        TemporaryDirectory::remove($this->outside());
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

    public function test_hidden_files_are_not_listed(): void
    {
        $key = $this->storage()->put('avatars', $this->png());
        touch($this->root . '/.gitignore');
        touch($this->root . '/avatars/.hidden');

        $this->assertSame([$key], array_keys($this->listed()));
    }

    public function test_a_hidden_directory_is_not_walked(): void
    {
        $this->place('.cache/objects/ab12.png');

        $this->assertSame([], $this->listed());
    }

    public function test_a_file_under_a_name_no_key_could_have_is_not_listed(): void
    {
        $this->place('avatars/x y.png');
        $this->place('avatars/résumé.png');

        $this->assertSame([], $this->listed());
    }

    public function test_a_file_placed_by_hand_under_a_valid_name_is_listed(): void
    {
        $this->place('brand/logo.png');

        $listed = $this->listed();

        $this->assertSame(['brand/logo.png'], array_keys($listed));
        $this->assertSame('image/png', $listed['brand/logo.png']->mimeType);
    }

    public function test_a_symlinked_file_is_not_listed(): void
    {
        $this->storage()->put('avatars', $this->png());
        $secret = $this->outside() . '/secret.png';
        mkdir($this->outside());
        file_put_contents($secret, base64_decode(self::PNG));
        symlink($secret, $this->root . '/avatars/secret.png');

        $this->assertNotContains('avatars/secret.png', array_keys($this->listed()));
    }

    public function test_a_symlinked_directory_is_not_walked_even_when_asked_for(): void
    {
        mkdir($this->outside() . '/deep', 0o775, true);
        file_put_contents($this->outside() . '/deep/secret.png', base64_decode(self::PNG));
        mkdir($this->root);
        symlink($this->outside(), $this->root . '/linked');

        $this->assertSame([], $this->listed());
        $this->assertSame([], $this->listed('linked'));
        $this->assertSame([], $this->listed('linked/deep'));
    }

    #[RequiresFunction('posix_mkfifo')]
    public function test_only_regular_files_are_listed(): void
    {
        // Sniffing a named pipe's type would block the walk on a read.
        $key = $this->storage()->put('avatars', $this->png());
        posix_mkfifo($this->root . '/avatars/pipe', 0o600);

        $this->assertSame([$key], array_keys($this->listed()));
    }

    public function test_a_file_deleted_during_the_walk_is_passed_over(): void
    {
        $keys = [];

        for ($i = 0; $i < 3; $i++) {
            $keys[] = $this->storage()->put('avatars', $this->png());
        }

        $seen = [];

        foreach ($this->storage()->list() as $file) {
            if ($seen === []) {
                foreach (array_diff($keys, [$file->key]) as $other) {
                    $this->storage()->delete($other);
                }
            }

            $seen[] = $file->key;
        }

        $this->assertCount(1, $seen);
    }

    public function test_a_root_not_yet_written_to_lists_nothing_and_is_not_created(): void
    {
        $this->assertSame([], $this->listed());
        $this->assertDirectoryDoesNotExist($this->root);
    }

    /** A PNG put straight on the disk, as someone copying files in would. */
    private function place(string $path): void
    {
        $full = $this->root . '/' . $path;

        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0o775, true);
        }

        file_put_contents($full, base64_decode(self::PNG));
    }

    private function outside(): string
    {
        return $this->root . '-outside';
    }
}
