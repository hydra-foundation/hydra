<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Exceptions\FileNotFound;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one decision every driver defers to: what a key may look like, and what
 * a stored file is called. The contract case exercises it through a disk; this
 * holds it to the letter.
 */
#[CoversClass(Key::class)]
#[CoversClass(InvalidKey::class)]
#[CoversClass(FileNotFound::class)]
final class KeyTest extends TestCase
{
    #[DataProvider('valid')]
    public function test_a_path_inside_the_disk_comes_back_unchanged(string $key): void
    {
        $this->assertSame($key, Key::valid($key));
    }

    /** @return iterable<string, array{string}> */
    public static function valid(): iterable
    {
        yield 'one segment' => ['avatars'];
        yield 'nested' => ['blog/2026/cover.png'];
        yield 'dots, dashes and underscores inside' => ['a_b-c.d/e.f.g'];
        yield 'a digit first' => ['2026/x'];
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_refused(string $key): void
    {
        $this->expectException(InvalidKey::class);

        Key::valid($key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'parent' => ['..'];
        yield 'current' => ['.'];
        yield 'hidden' => ['.env'];
        yield 'leading slash' => ['/avatars'];
        yield 'trailing slash' => ['avatars/'];
        yield 'empty segment' => ['a//b'];
        yield 'backslash' => ['a\\b'];
        yield 'nul' => ["a\0b"];
        yield 'newline at the end' => ["avatars\n"];
        yield 'colon' => ['private:avatars'];
        yield 'unicode' => ['avätars'];
    }

    public function test_a_fresh_key_is_the_directory_32_hex_characters_and_the_type_s_extension(): void
    {
        $this->assertMatchesRegularExpression('#^blog/2026/[0-9a-f]{32}\.webp$#D', Key::fresh('blog/2026', 'image/webp'));
    }

    public function test_a_fresh_key_is_never_made_in_a_directory_outside_the_disk(): void
    {
        $this->expectException(InvalidKey::class);

        Key::fresh('../avatars', 'image/png');
    }

    #[DataProvider('extensions')]
    public function test_each_known_type_has_its_extension(string $type, string $extension): void
    {
        $this->assertSame($extension, Key::extensionFor($type));
    }

    /** @return iterable<array-key, array{string, string}> */
    public static function extensions(): iterable
    {
        yield ['image/jpeg', 'jpg'];
        yield ['image/png', 'png'];
        yield ['image/gif', 'gif'];
        yield ['image/webp', 'webp'];
        yield ['image/avif', 'avif'];
        yield ['application/pdf', 'pdf'];
        yield ['text/plain', 'txt'];
        yield ['text/csv', 'csv'];
        yield ['application/json', 'json'];
        yield ['application/zip', 'zip'];
        yield 'any case' => ['IMAGE/PNG', 'png'];
        yield 'a script' => ['text/x-php', 'bin'];
        yield 'an svg' => ['image/svg+xml', 'bin'];
        yield 'nothing known' => ['application/octet-stream', 'bin'];
    }

    public function test_a_refused_key_is_quoted_so_a_control_byte_shows_as_what_it_is(): void
    {
        $this->assertSame('"a/b\\u0000c" is not a path inside the disk.', InvalidKey::of("a/b\0c")->getMessage());
    }

    public function test_an_unknown_disk_is_named(): void
    {
        $this->assertSame('There is no disk named "s3".', InvalidKey::unknownDisk('s3')->getMessage());
    }

    public function test_a_missing_file_names_its_key(): void
    {
        $this->assertSame('No file is stored at "avatars/a.png".', FileNotFound::at('avatars/a.png')->getMessage());
    }
}
