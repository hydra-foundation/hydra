<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Maintenance;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Maintenance::class)]
final class MaintenanceTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-maintenance-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/cache/maintenance.json';
    }

    protected function tearDown(): void
    {
        @chmod($this->dir . '/cache', 0775);
        array_map('unlink', glob($this->dir . '/cache/*') ?: []);
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    public function test_up_until_taken_down(): void
    {
        $this->assertNull((new Maintenance($this->path))->current());
    }

    public function test_down_is_read_back_with_its_message_retry_and_time(): void
    {
        $maintenance = new Maintenance($this->path);
        $before = time();

        $maintenance->down('Upgrading the database.', 300);
        $down = $maintenance->current();

        $this->assertNotNull($down);
        $this->assertSame('Upgrading the database.', $down['message']);
        $this->assertSame(300, $down['retry']);
        $this->assertGreaterThanOrEqual($before, $down['since']);
        $this->assertSame([$this->path], glob($this->dir . '/cache/*'), 'nothing left beside the flag');
    }

    public function test_the_flag_is_stored_readably(): void
    {
        (new Maintenance($this->path))->down('Café closed / back at 12:00', 1);

        $raw = (string) file_get_contents($this->path);

        $this->assertStringContainsString('"Café closed / back at 12:00"', $raw);
        $this->assertStringContainsString('"retry":1', $raw);
    }

    public function test_down_again_replaces_the_message(): void
    {
        $maintenance = new Maintenance($this->path);

        $maintenance->down('first', 60);
        $maintenance->down('second');

        $down = $maintenance->current();
        $this->assertNotNull($down);
        $this->assertSame('second', $down['message']);
        $this->assertNull($down['retry']);
    }

    public function test_up_removes_the_flag_and_says_whether_there_was_one(): void
    {
        $maintenance = new Maintenance($this->path);
        $maintenance->down();

        $this->assertTrue($maintenance->up());
        $this->assertNull($maintenance->current());
        $this->assertFalse($maintenance->up());
    }

    public function test_a_retry_below_one_second_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Maintenance($this->path))->down('x', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['down'];
        yield 'a string' => ['"down"'];
        yield 'wrong types' => ['{"message":7,"retry":"soon","since":"today"}'];
        yield 'empty message, negative retry' => ['{"message":"","retry":-5}'];
        yield 'a retry of zero' => ['{"retry":0}'];
    }

    #[DataProvider('unreadable')]
    public function test_a_flag_that_will_not_parse_still_means_down(string $contents): void
    {
        mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, $contents);

        $this->assertSame(
            ['message' => Maintenance::DEFAULT_MESSAGE, 'retry' => null, 'since' => null],
            (new Maintenance($this->path))->current(),
        );
    }

    public function test_a_flag_directory_that_cannot_be_made_is_an_error(): void
    {
        mkdir($this->dir);
        touch($this->dir . '/cache');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not create');

            (new Maintenance($this->path))->down();
        } finally {
            unlink($this->dir . '/cache');
        }
    }

    public function test_a_flag_that_cannot_be_written_is_an_error_and_leaves_nothing(): void
    {
        $this->readOnlyDirectory();

        try {
            (new Maintenance($this->path))->down();
            $this->fail('An unwritable flag must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not write the maintenance flag', $e->getMessage());
        }

        chmod($this->dir . '/cache', 0775);
        $this->assertSame([], glob($this->dir . '/cache/*'));
    }

    public function test_a_flag_that_cannot_be_removed_is_an_error(): void
    {
        $maintenance = new Maintenance($this->path);
        $maintenance->down();
        $this->readOnlyDirectory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not remove the maintenance flag');

        $maintenance->up();
    }

    private function readOnlyDirectory(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root writes through a read-only directory.');
        }

        @mkdir($this->dir . '/cache', 0775, true);
        chmod($this->dir . '/cache', 0555);
    }
}
