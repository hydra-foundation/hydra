<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\Commands\MakeMigrationCommand;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * make:migration stays in console while the migrate:* commands live in
 * database: it writes a timestamped file and never opens a connection, so it
 * needs a directory and a clock rather than the database layer.
 */
#[CoversClass(MakeMigrationCommand::class)]
final class MakeMigrationCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-make-migration-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_make_migration_creates_timestamped_file(): void
    {
        $command = new MakeMigrationCommand($this->dir);
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Success, $command->execute(ArrayInput::withArguments(['name' => 'Create Posts Table']), $output));

        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{8}_\d{6}_create_posts_table\.sql$/', $files[0]);

        $body = file_get_contents($this->dir . '/' . $files[0]);
        $this->assertStringContainsString('-- Migration: Create Posts Table', $body);
        $this->assertStringContainsString('Forward-only', $body);
    }

    public function test_make_migration_takes_its_stamp_from_the_clock(): void
    {
        $command = new MakeMigrationCommand($this->dir, new FrozenClock('2026-03-04T05:06:07+00:00'));

        $command->execute(ArrayInput::withArguments(['name' => 'add slugs']), new FakeOutput);

        $path = $this->dir . '/20260304_050607_add_slugs.sql';
        $this->assertFileExists($path);
        $this->assertStringContainsString('-- Created: 2026-03-04 05:06:07', (string) file_get_contents($path));
    }

    public function test_make_migration_rejects_an_empty_slug(): void
    {
        $command = new MakeMigrationCommand($this->dir);
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Failure, $command->execute(ArrayInput::withArguments(['name' => '!!!']), $output));
        $this->assertSame([], glob($this->dir . '/*.sql') ?: []);
    }
}
