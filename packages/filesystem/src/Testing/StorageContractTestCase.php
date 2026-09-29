<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Testing;

use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\FileNotFound;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;
use Hydra\Filesystem\StoredFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The behaviour every disk owes the code that stores to it, published so a
 * driver written later (an object store, say) is held to the same promises as
 * the local one.
 *
 * Two of them are the reason the package exists. The key is the disk's to
 * choose, from the bytes and nothing the client said: a file that is a PNG is
 * stored as .png whatever it was called on the way in. And a key is only ever a
 * path inside the disk: one that climbs out, starts at the root or smuggles a
 * byte a filesystem treats specially is refused before anything is opened.
 */
#[CoversClass(Key::class)]
abstract class StorageContractTestCase extends TestCase
{
    /**
     * A 1×1 transparent PNG: the smallest real image, so the type is detected
     * rather than assumed.
     */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** An empty disk, the same instance for the whole of one test. */
    abstract protected function storage(): StorageInterface;

    /**
     * Where the case gets its streams from, asked for rather than assumed so a
     * driver's own package need not depend on any one PSR-7 implementation.
     */
    abstract protected function streams(): StreamFactoryInterface;

    public function test_put_chooses_a_key_in_the_directory_from_the_detected_type(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#', $key);
    }

    public function test_put_accepts_a_nested_directory(): void
    {
        $key = $this->storage()->put('blog/2026', $this->png());

        $this->assertStringStartsWith('blog/2026/', $key);
    }

    public function test_two_puts_of_the_same_bytes_get_two_keys(): void
    {
        $this->assertNotSame(
            $this->storage()->put('avatars', $this->png()),
            $this->storage()->put('avatars', $this->png()),
        );
    }

    public function test_a_stored_file_reads_back_byte_for_byte(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertSame(base64_decode(self::PNG), (string) $this->storage()->read($key));
    }

    public function test_a_stored_file_knows_its_size_and_type(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertSame(strlen(base64_decode(self::PNG)), $this->storage()->size($key));
        $this->assertSame('image/png', $this->storage()->mimeType($key));
    }

    public function test_bytes_of_no_known_type_are_stored_as_bin(): void
    {
        // Fixed bytes, not random ones: libmagic reads about one random 64-byte
        // string in a hundred as some type it knows (a DOS executable, plain
        // text, zlib), which failed this test at random. Half of these are
        // NUL, and they begin with no signature any file type has.
        $key = $this->storage()->put('files', $this->streams()->createStream(str_repeat("\xff\x00", 32)));

        $this->assertStringEndsWith('.bin', $key);
    }

    public function test_plain_text_is_stored_as_txt(): void
    {
        $key = $this->storage()->put('files', $this->streams()->createStream("Just a note, nothing more.\n"));

        $this->assertStringEndsWith('.txt', $key);
        $this->assertSame('text/plain', $this->storage()->mimeType($key));
    }

    public function test_a_script_is_never_stored_under_its_own_extension(): void
    {
        $key = $this->storage()->put('files', $this->streams()->createStream("<?php echo 'hi';\n"));

        $this->assertStringEndsWith('.bin', $key);
    }

    public function test_exists_answers_for_stored_and_unknown_keys(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->assertTrue($this->storage()->exists($key));
        $this->assertFalse($this->storage()->exists('avatars/' . str_repeat('0', 32) . '.png'));
    }

    public function test_a_deleted_file_is_gone(): void
    {
        $key = $this->storage()->put('avatars', $this->png());

        $this->storage()->delete($key);

        $this->assertFalse($this->storage()->exists($key));
    }

    public function test_deleting_a_missing_key_is_not_an_error(): void
    {
        $this->storage()->delete('avatars/' . str_repeat('0', 32) . '.png');

        $this->addToAssertionCount(1);
    }

    public function test_reading_a_missing_key_throws(): void
    {
        $this->expectException(FileNotFound::class);

        $this->storage()->read('avatars/' . str_repeat('0', 32) . '.png');
    }

    public function test_the_size_of_a_missing_key_throws(): void
    {
        $this->expectException(FileNotFound::class);

        $this->storage()->size('avatars/' . str_repeat('0', 32) . '.png');
    }

    public function test_an_empty_disk_lists_nothing(): void
    {
        $this->assertSame([], $this->listed());
    }

    public function test_every_stored_file_is_listed_once_as_its_other_methods_describe_it(): void
    {
        $png = $this->storage()->put('avatars', $this->png());
        $text = $this->storage()->put('blog/2026', $this->streams()->createStream("A post.\n"));

        $listed = $this->listed();

        $this->assertEqualsCanonicalizing([$png, $text], array_keys($listed));

        foreach ([$png, $text] as $key) {
            $this->assertSame($this->storage()->size($key), $listed[$key]->size);
            $this->assertSame($this->storage()->mimeType($key), $listed[$key]->mimeType);
        }
    }

    public function test_a_listed_file_was_modified_when_it_was_stored_and_says_so_in_utc(): void
    {
        $before = time();
        $key = $this->storage()->put('avatars', $this->png());
        $after = time();

        $listed = $this->listed();
        $this->assertArrayHasKey($key, $listed);
        $modified = $listed[$key]->modifiedAt;

        $this->assertGreaterThanOrEqual($before, $modified->getTimestamp());
        $this->assertLessThanOrEqual($after, $modified->getTimestamp());
        $this->assertSame(0, $modified->getOffset());
    }

    public function test_a_listing_is_limited_to_its_directory_at_any_depth(): void
    {
        $post = $this->storage()->put('blog/2026', $this->png());
        $this->storage()->put('avatars', $this->png());

        $this->assertSame([$post], array_keys($this->listed('blog')));
        $this->assertSame([$post], array_keys($this->listed('blog/2026')));
    }

    public function test_a_directory_matches_whole_segments_only(): void
    {
        $this->storage()->put('blog-old', $this->png());

        $this->assertSame([], $this->listed('blog'));
    }

    public function test_a_directory_never_written_to_lists_nothing(): void
    {
        $this->storage()->put('avatars', $this->png());

        $this->assertSame([], $this->listed('invoices'));
    }

    public function test_a_deleted_file_is_not_listed(): void
    {
        $kept = $this->storage()->put('avatars', $this->png());
        $gone = $this->storage()->put('avatars', $this->png());

        $this->storage()->delete($gone);

        $this->assertSame([$kept], array_keys($this->listed()));
    }

    #[DataProvider('invalidKeys')]
    public function test_a_directory_outside_the_disk_is_refused_by_list_before_it_is_walked(string $directory): void
    {
        $this->expectException(InvalidKey::class);

        // Not iterated: a lazy listing must still refuse on the call.
        $this->storage()->list($directory);
    }

    #[DataProvider('invalidKeys')]
    public function test_a_key_outside_the_disk_is_refused_by_every_method(string $key): void
    {
        foreach (['exists', 'read', 'size', 'mimeType', 'delete'] as $method) {
            try {
                $this->storage()->{$method}($key);
                $this->fail("{$method}() accepted the key " . json_encode($key));
            } catch (InvalidKey) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidKeys')]
    public function test_a_directory_outside_the_disk_is_refused(string $directory): void
    {
        $this->expectException(InvalidKey::class);

        $this->storage()->put($directory, $this->png());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'parent' => ['../etc/passwd'];
        yield 'parent in the middle' => ['avatars/../../etc/passwd'];
        yield 'current directory' => ['avatars/./x.png'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'backslash' => ['avatars\\..\\x.png'];
        yield 'nul byte' => ["avatars/x.png\0.txt"];
        yield 'empty segment' => ['avatars//x.png'];
        yield 'trailing slash' => ['avatars/'];
        yield 'hidden file' => ['avatars/.htaccess'];
        yield 'space' => ['avatars/x y.png'];
    }

    /**
     * A listing by key, failing on a key listed twice or anything that is not
     * a StoredFile.
     *
     * @return array<string, StoredFile>
     */
    protected function listed(?string $directory = null): array
    {
        $files = [];

        foreach ($this->storage()->list($directory) as $file) {
            $this->assertInstanceOf(StoredFile::class, $file);
            $this->assertArrayNotHasKey($file->key, $files, "{$file->key} was listed twice.");
            $files[$file->key] = $file;
        }

        return $files;
    }

    protected function png(): StreamInterface
    {
        return $this->streams()->createStream(base64_decode(self::PNG));
    }
}
