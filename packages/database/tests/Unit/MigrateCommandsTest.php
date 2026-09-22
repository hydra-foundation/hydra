<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Database\Console\MigrateFreshCommand;
use Hydra\Database\Console\MigrateRunCommand;
use Hydra\Database\Console\MigrateStatusCommand;
use Hydra\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The migrate:* commands wired to a real MigrationRunner over an in-memory
 * sqlite PDO and a temp directory, covering the dev-mode guard on migrate:fresh.
 */
#[CoversClass(MigrateFreshCommand::class)]
#[CoversClass(MigrateRunCommand::class)]
#[CoversClass(MigrateStatusCommand::class)]
final class MigrateCommandsTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->dir = sys_get_temp_dir() . '/hydra-migrate-cmd-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->dir, 'sqlite');
    }

    private function write(string $filename, string $sql): void
    {
        file_put_contents($this->dir . '/' . $filename, $sql);
    }

    public function test_migrate_run_applies_and_is_idempotent(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $command = new MigrateRunCommand($this->runner());
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Success, $command->execute(new ArrayInput, $output));
        $output->assertSaid('Applied 1 migration');

        $command = new MigrateRunCommand($this->runner());
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Success, $command->execute(new ArrayInput, $output));
        $output->assertSaid('up to date');
    }

    public function test_migrate_status_shows_applied_and_pending(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $this->runner()->run();
        $this->write('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $command = new MigrateStatusCommand($this->runner());
        $output = new FakeOutput;
        $command->execute(new ArrayInput, $output);

        $display = implode("\n", $output->lines());
        $this->assertStringContainsString('applied', $display);
        $this->assertStringContainsString('pending', $display);
    }

    public function test_migrate_fresh_refuses_outside_debug_without_force(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $command = new MigrateFreshCommand($this->runner(), debug: false);
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Failure, $command->execute(new ArrayInput, $output));
        $output->assertSaid('APP_DEBUG is off');
    }

    public function test_migrate_fresh_runs_outside_debug_with_force(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $command = new MigrateFreshCommand($this->runner(), debug: false);
        $output = new FakeOutput;
        $this->assertSame(ExitCode::Success, $command->execute(ArrayInput::withFlags(['force']), $output));
        $output->assertSaid('Database reset');
    }

    public function test_migrate_fresh_confirms_in_debug_mode(): void
    {
        $this->write('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $command = new MigrateFreshCommand($this->runner(), debug: true);
        $output = new FakeOutput;
        $output->willConfirm([true]);
        $this->assertSame(ExitCode::Success, $command->execute(new ArrayInput, $output));
        $output->assertSaid('Database reset');
    }

    public function test_migrate_fresh_aborts_when_declined(): void
    {
        $command = new MigrateFreshCommand($this->runner(), debug: true);
        $output = new FakeOutput;
        $output->willConfirm([false]);
        $this->assertSame(ExitCode::Failure, $command->execute(new ArrayInput, $output));
        $output->assertSaid('Aborted');
    }
}
