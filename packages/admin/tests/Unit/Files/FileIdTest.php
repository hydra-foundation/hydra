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
        $id = FileId::of('private:blog/2026/0123456789abcdef0123456789abcdef.png');

        $this->assertSame('private~blog~2026~0123456789abcdef0123456789abcdef.png', $id);
        $this->assertSame(rawurlencode($id), $id);
        $this->assertSame('private:blog/2026/0123456789abcdef0123456789abcdef.png', FileId::qualified($id));
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
        yield 'empty' => [''];
        yield 'no separator' => ['private'];
        yield 'no key' => ['private~'];
        yield 'unknown disk' => ['elsewhere~avatars~a.png'];
        yield 'empty segment' => ['private~avatars~~a.png'];
        yield 'climbs out' => ['private~..~etc~passwd'];
        yield 'hidden file' => ['private~avatars~.htaccess'];
        yield 'a slash sneaked in' => ['private~avatars/a.png'];
        yield 'a colon sneaked in' => ['private~avatars:a.png'];
        yield 'a number' => ['7'];
    }
}
