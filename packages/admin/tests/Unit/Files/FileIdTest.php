<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Files;

use Hydra\Admin\Files\FileId;
use Hydra\Filesystem\Exceptions\InvalidKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileId::class)]
final class FileIdTest extends TestCase
{
    public function test_a_qualified_key_becomes_one_url_segment_and_back(): void
    {
        $qualified = 'private:blog/2026/0123456789abcdef0123456789abcdef.png';
        $id = FileId::of($qualified);

        $this->assertSame(rawurlencode($id), $id);
        $this->assertStringNotContainsString('/', $id);
        $this->assertSame($qualified, FileId::qualified($id));
    }

    public function test_an_id_never_looks_like_a_file_a_web_server_would_answer_for(): void
    {
        // nginx's static rules (location ~* \.(?:jpg|png|css|js)$ and the
        // like) answer a path ending in an extension without asking PHP, so a
        // show screen at /admin/files/<id>.jpg is a 404 in production.
        foreach (['a.jpg', 'a.png', 'a.css', 'a.js', 'a.woff2', 'a.bin'] as $file) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', FileId::of('public:covers/' . $file));
        }
    }

    public function test_a_public_key_round_trips_too(): void
    {
        $this->assertSame('public:covers/a.png', FileId::qualified(FileId::of('public:covers/a.png')));
    }

    public function test_only_a_key_can_be_made_an_id(): void
    {
        $this->expectException(InvalidKey::class);

        FileId::of('private:../etc/passwd');
    }

    #[DataProvider('notIds')]
    public function test_an_id_naming_no_key_is_null(string $id): void
    {
        $this->assertNull(FileId::qualified($id));
    }

    /** @return iterable<string, array{string}> */
    public static function notIds(): iterable
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        yield 'empty' => [''];
        yield 'not base64url' => ['private:avatars/a.png'];
        yield 'padding' => [$encode('private:avatars/a.png') . '='];
        yield 'no disk' => [$encode('avatars/a.png')];
        yield 'unknown disk' => [$encode('elsewhere:avatars/a.png')];
        yield 'empty segment' => [$encode('private:avatars//a.png')];
        yield 'climbs out' => [$encode('private:../etc/passwd')];
        yield 'hidden file' => [$encode('private:avatars/.htaccess')];
        yield 'a number' => ['7'];
    }
}
