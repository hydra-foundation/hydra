<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Disks;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\LocalPublicStorage;
use Hydra\Filesystem\LocalStorage;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Disks::class)]
final class DisksTest extends TestCase
{
    private LocalStorage $private;
    private LocalPublicStorage $public;
    private Disks $disks;

    protected function setUp(): void
    {
        $streams = new Psr17Factory;
        $this->private = new LocalStorage('/tmp/private', $streams);
        $this->public = new LocalPublicStorage('/tmp/public', '/storage', $streams);
        $this->disks = new Disks($this->private, $this->public);
    }

    public function test_each_disk_is_reachable_by_name(): void
    {
        $this->assertSame($this->private, $this->disks->get(Disks::PRIVATE));
        $this->assertSame($this->public, $this->disks->get(Disks::PUBLIC));
    }

    public function test_the_named_accessors_return_the_same_disks(): void
    {
        $this->assertSame($this->private, $this->disks->private());
        $this->assertSame($this->public, $this->disks->public());
    }

    public function test_an_unknown_disk_is_refused(): void
    {
        $this->expectException(InvalidKey::class);

        $this->disks->get('s3');
    }

    public function test_a_qualified_key_names_its_disk_and_its_key(): void
    {
        $this->assertSame('public:blog/a.png', $this->disks->qualify(Disks::PUBLIC, 'blog/a.png'));
        $this->assertSame([$this->public, 'blog/a.png'], $this->disks->locate('public:blog/a.png'));
        $this->assertSame([$this->private, 'avatars/a.png'], $this->disks->locate('private:avatars/a.png'));
    }

    public function test_the_disk_of_a_qualified_key_is_readable_without_resolving_it(): void
    {
        $this->assertTrue($this->disks->isPublic('public:blog/a.png'));
        $this->assertFalse($this->disks->isPublic('private:avatars/a.png'));
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_qualified_key_is_refused(string $qualified): void
    {
        $this->expectException(InvalidKey::class);

        $this->disks->locate($qualified);
    }

    public function test_a_key_is_never_qualified_with_a_disk_that_does_not_exist(): void
    {
        $this->expectException(InvalidKey::class);

        $this->disks->qualify('s3', 'blog/a.png');
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'no disk' => ['avatars/a.png'];
        yield 'unknown disk' => ['s3:avatars/a.png'];
        yield 'empty key' => ['private:'];
        yield 'traversal' => ['private:../.env'];
        yield 'empty' => [''];
        yield 'two colons' => ['private:avatars:a.png'];
    }
}
