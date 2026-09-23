<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use Hydra\Scheduler\HeldLock;
use Hydra\Scheduler\LockDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LockDirectory::class)]
#[CoversClass(HeldLock::class)]
final class LockDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-scheduler-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/nested', $this->dir] as $dir) {
            array_map('unlink', glob($dir . '/*.lock') ?: []);
            @rmdir($dir);
        }
    }

    public function test_a_held_lock_cannot_be_taken_again_until_released(): void
    {
        $locks = new LockDirectory($this->dir);
        $now = new DateTimeImmutable('2026-09-23 04:00 UTC');

        $first = $locks->acquire('App\\Jobs\\Sitemap', $now);
        $this->assertNotNull($first);
        $this->assertNull($locks->acquire('App\\Jobs\\Sitemap', $now));

        $first->release();
        $first->release();

        $this->assertNotNull($locks->acquire('App\\Jobs\\Sitemap', $now));
    }

    public function test_each_name_has_its_own_lock(): void
    {
        $locks = new LockDirectory($this->dir);
        $now = new DateTimeImmutable();

        $this->assertNotNull($locks->acquire('A', $now));
        $this->assertNotNull($locks->acquire('B', $now));
    }

    public function test_the_holder_records_when_it_started(): void
    {
        $locks = new LockDirectory($this->dir);
        $now = new DateTimeImmutable('2026-09-23 04:00 UTC');

        $this->assertNull($locks->startedAt('A'));

        $locks->acquire('A', $now);

        $this->assertSame($now->getTimestamp(), $locks->startedAt('A'));
    }

    public function test_the_directory_is_made_when_missing(): void
    {
        $locks = new LockDirectory($this->dir . '/nested');

        $this->assertNotNull($locks->acquire('A', new DateTimeImmutable()));
        $this->assertDirectoryExists($this->dir . '/nested');
    }

    public function test_a_directory_it_cannot_make_is_named_in_the_error(): void
    {
        touch($this->dir . '-file');
        $locks = new LockDirectory($this->dir . '-file/locks');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cannot create its lock directory');

            $locks->acquire('A', new DateTimeImmutable());
        } finally {
            unlink($this->dir . '-file');
        }
    }
}
