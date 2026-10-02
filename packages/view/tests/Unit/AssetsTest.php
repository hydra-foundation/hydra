<?php

declare(strict_types=1);

namespace Hydra\View\Tests\Unit;

use Hydra\View\AssetNotFound;
use Hydra\View\Assets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Assets against a real public directory in a scratch root, with a file one
 * level above it, so the refusals are proven against something that exists
 * rather than passing because the file was missing anyway.
 */
#[CoversClass(Assets::class)]
#[CoversClass(AssetNotFound::class)]
final class AssetsTest extends TestCase
{
    private string $root;
    private string $public;
    private Assets $assets;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hydra-assets-' . uniqid('', true);
        $this->public = $this->root . '/public';
        mkdir($this->public . '/css/vendor', 0777, true);
        mkdir($this->public . '/js', 0777, true);
        mkdir($this->public . '/dir.css');

        file_put_contents($this->public . '/css/app.css', 'body { color: red; }');
        file_put_contents($this->public . '/css/vendor/bootstrap.min.css', '.btn{}');
        file_put_contents($this->public . '/js/a.b.c.js', 'void 0;');
        file_put_contents($this->public . '/LICENSE', 'MIT');
        file_put_contents($this->root . '/secret.css', 'outside');

        $this->assets = new Assets($this->public);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private static function hash(string $bytes): string
    {
        return substr(hash('xxh128', $bytes), 0, 10);
    }

    public function test_the_hash_goes_before_the_extension(): void
    {
        $hash = self::hash('body { color: red; }');

        self::assertSame("/css/app.$hash.css", $this->assets->url('/css/app.css'));
    }

    public function test_the_hash_goes_before_the_last_extension_only(): void
    {
        self::assertSame(
            '/css/vendor/bootstrap.min.' . self::hash('.btn{}') . '.css',
            $this->assets->url('/css/vendor/bootstrap.min.css'),
        );
        self::assertSame('/js/a.b.c.' . self::hash('void 0;') . '.js', $this->assets->url('/js/a.b.c.js'));
    }

    public function test_the_hash_is_ten_lowercase_hex_characters(): void
    {
        self::assertMatchesRegularExpression('#^/css/app\.[0-9a-f]{10}\.css$#', $this->assets->url('/css/app.css'));
    }

    public function test_the_same_bytes_give_the_same_name_and_a_changed_byte_another(): void
    {
        $first = $this->assets->url('/css/app.css');
        self::assertSame($first, (new Assets($this->public))->url('/css/app.css'));

        file_put_contents($this->public . '/css/app.css', 'body { color: blue; }');

        self::assertNotSame($first, (new Assets($this->public))->url('/css/app.css'));
    }

    public function test_a_second_call_does_not_read_the_file_again(): void
    {
        $first = $this->assets->url('/css/app.css');
        unlink($this->public . '/css/app.css');

        self::assertSame($first, $this->assets->url('/css/app.css'));
    }

    public function test_a_public_path_with_a_trailing_slash_is_the_same_directory(): void
    {
        self::assertSame($this->assets->url('/css/app.css'), (new Assets($this->public . '/'))->url('/css/app.css'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'no leading slash' => ['css/app.css', 'must start with "/", from the public directory: got "css/app.css". Link a file on another host by its URL, without asset().'];
        yield 'empty' => ['', 'must start with "/"'];
        yield 'protocol-relative' => ['//cdn.example.com/app.css', 'another host'];
        yield 'full URL' => ['https://cdn.example.com/app.css', 'must start with "/"'];
        yield 'climbs out' => ['/../secret.css', '".."'];
        yield 'climbs inside' => ['/css/../css/app.css', '".."'];
        yield 'no extension' => ['/LICENSE', '"/LICENSE" has no extension. The hash goes before the extension, and the web server serves hashed names by extension, so a file without one cannot be fingerprinted.'];
        yield 'a dotfile' => ['/.env', 'no extension'];
        yield 'a trailing dot' => ['/css/app.', 'no extension'];
        yield 'missing' => ['/css/ap.css', 'is not in'];
        yield 'a directory' => ['/dir.css', 'is not in'];
        yield 'query string' => ['/css/app.css?v=1', 'query'];
        yield 'fragment' => ['/css/app.css#x', 'query'];
        yield 'null byte' => ["/css/app.css\0.php", 'null byte'];
    }

    #[DataProvider('refused')]
    public function test_it_refuses(string $path, string $says): void
    {
        $this->expectException(AssetNotFound::class);
        $this->expectExceptionMessage($says);

        $this->assets->url($path);
    }

    public function test_the_missing_file_message_names_the_path_and_the_directory(): void
    {
        try {
            $this->assets->url('/css/ap.css');
            self::fail('Expected AssetNotFound.');
        } catch (AssetNotFound $e) {
            self::assertStringContainsString('/css/ap.css', $e->getMessage());
            self::assertStringContainsString($this->public, $e->getMessage());
            self::assertStringContainsString('deployed', $e->getMessage());
        }
    }

    public function test_a_symlink_out_of_the_public_directory_is_refused(): void
    {
        symlink($this->root . '/secret.css', $this->public . '/css/linked.css');

        $this->expectException(AssetNotFound::class);
        $this->expectExceptionMessage('outside');

        $this->assets->url('/css/linked.css');
    }

    public function test_a_symlink_into_a_sibling_whose_name_starts_the_same_is_refused(): void
    {
        // public-old/ shares public's prefix as a string; only the separator
        // after it tells the two directories apart.
        mkdir($this->public . '-old');
        file_put_contents($this->public . '-old/app.css', 'old');
        symlink($this->public . '-old/app.css', $this->public . '/css/old.css');

        $this->expectException(AssetNotFound::class);
        $this->expectExceptionMessage('outside');

        $this->assets->url('/css/old.css');
    }

    public function test_a_symlink_inside_the_public_directory_is_followed(): void
    {
        symlink($this->public . '/css/app.css', $this->public . '/css/alias.css');

        self::assertSame(
            '/css/alias.' . self::hash('body { color: red; }') . '.css',
            $this->assets->url('/css/alias.css'),
        );
    }

    public function test_a_public_directory_that_does_not_exist_is_refused(): void
    {
        $this->expectException(AssetNotFound::class);
        $this->expectExceptionMessage('is not a directory');

        (new Assets($this->root . '/nope'))->url('/css/app.css');
    }
}
