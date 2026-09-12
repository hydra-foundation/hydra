<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MigrationRunner against an in-memory sqlite PDO (the test driver) and a
 * temporary migrations directory — its contract: apply pending .sql files in
 * order, track them so re-runs are no-ops, report status, and reset on fresh().
 */
final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->dir = sys_get_temp_dir() . '/hydra-migrations-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            chmod($file, 0o644); // undo the unreadable-file test's chmod
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->dir, 'sqlite');
    }

    private function writeMigration(string $filename, string $sql): void
    {
        file_put_contents($this->dir . '/' . $filename, $sql);
    }

    public function test_run_applies_pending_migrations_in_lexical_order(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $this->writeMigration('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $applied = $this->runner()->run();

        $this->assertSame(
            ['20260101_000000_create_a.sql', '20260102_000000_create_b.sql'],
            $applied,
        );

        // Both tables exist — the DDL actually ran.
        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('a', 'b') ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['a', 'b'], $tables);
    }

    public function test_rerunning_applies_nothing_further(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $this->assertCount(1, $this->runner()->run());
        $this->assertSame([], $this->runner()->run());
    }

    public function test_run_applies_only_newly_added_migrations(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $this->runner()->run();

        $this->writeMigration('20260103_000000_create_c.sql', 'CREATE TABLE c (id INTEGER PRIMARY KEY)');

        $this->assertSame(['20260103_000000_create_c.sql'], $this->runner()->run());
    }

    public function test_pending_lists_unapplied_files(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $this->writeMigration('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $runner = $this->runner();
        $this->assertCount(2, $runner->pending());

        $runner->run();
        $this->assertSame([], $runner->pending());
    }

    public function test_status_reports_applied_and_pending(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $runner = $this->runner();
        $runner->run();

        $this->writeMigration('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $this->assertSame(
            [
                ['filename' => '20260101_000000_create_a.sql', 'applied' => true],
                ['filename' => '20260102_000000_create_b.sql', 'applied' => false],
            ],
            $runner->status(),
        );
    }

    public function test_fresh_drops_all_tables_and_reapplies_everything(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        $runner = $this->runner();
        $runner->run();

        // A stray table not produced by a migration — fresh() must drop it too.
        $this->pdo->exec('CREATE TABLE stray (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO a (id) VALUES (1)');

        $applied = $runner->fresh();

        $this->assertSame(['20260101_000000_create_a.sql'], $applied);

        // The stray is gone, `a` is back and empty.
        $remaining = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'stray'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], $remaining);
        $this->assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM a')->fetchColumn());
    }

    public function test_status_on_empty_directory_is_empty(): void
    {
        $this->assertSame([], $this->runner()->status());
    }

    public function test_failing_migration_throws_and_is_not_recorded_and_halts_later_files(): void
    {
        $this->writeMigration('20260101_000000_bad.sql', 'CREATE BOGUS this is not sql');
        $this->writeMigration('20260102_000000_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY)');

        $runner = $this->runner();

        try {
            $runner->run();
            $this->fail('Expected the failing migration to throw');
        } catch (PDOException) {
            // expected
        }

        // Neither file is recorded as applied — the bad one failed, the good
        // one never ran on top of the broken state.
        $this->assertSame(
            [
                ['filename' => '20260101_000000_bad.sql', 'applied' => false],
                ['filename' => '20260102_000000_create_b.sql', 'applied' => false],
            ],
            $runner->status(),
        );

        // And the later file's DDL really did not execute.
        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'b'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], $tables);
    }

    /**
     * A multi-statement file whose *later* statement fails must surface the
     * error and stay unrecorded — the 2026-07-06 regression was errors after
     * the first statement being swallowed and the file marked applied.
     *
     * This exercises the sqlite branch of execute() (plain exec(), which runs
     * the whole string and reports any statement's failure honestly). The
     * pdo_mysql query()/nextRowset() draining path that the same fix hardened
     * needs a real MySQL server and cannot be reached from this sqlite harness.
     */
    public function test_multi_statement_migration_failing_on_a_later_statement_is_not_recorded(): void
    {
        $this->writeMigration(
            '20260101_000000_multi.sql',
            "CREATE TABLE ok (id INTEGER PRIMARY KEY);\n"
            . 'CREATE BOGUS this is not sql;',
        );

        $runner = $this->runner();

        try {
            $runner->run();
            $this->fail('Expected the later failing statement to throw');
        } catch (PDOException) {
            // expected — the second statement's error surfaced.
        }

        // The file is not recorded despite its first statement having run.
        $this->assertSame(
            [['filename' => '20260101_000000_multi.sql', 'applied' => false]],
            $runner->status(),
        );
    }

    public function test_unreadable_migration_file_throws_naming_the_file(): void
    {
        $this->writeMigration('20260101_000000_locked.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');
        chmod($this->dir . '/20260101_000000_locked.sql', 0o000);

        if (is_readable($this->dir . '/20260101_000000_locked.sql')) {
            $this->markTestSkipped('Running as a user that ignores file permissions (root)');
        }

        $runner = $this->runner();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('20260101_000000_locked.sql');

        $runner->run();
    }

    public function test_failed_run_leaves_the_file_pending_so_a_fixed_version_reruns(): void
    {
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE BOGUS this is not sql');
        $runner = $this->runner();

        try {
            $runner->run();
        } catch (PDOException) {
            // expected
        }

        // Fix the file in place; the runner must still consider it pending.
        $this->writeMigration('20260101_000000_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY)');

        $this->assertSame(['20260101_000000_create_a.sql'], $runner->run());
    }

    public function test_constructor_enforces_exception_error_mode_on_the_injected_pdo(): void
    {
        $silent = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        ]);

        $this->writeMigration('20260101_000000_bad.sql', 'CREATE BOGUS this is not sql');
        $runner = new MigrationRunner($silent, $this->dir, 'sqlite');

        // Under ERRMODE_SILENT this would return false and be recorded as
        // applied; the constructor must have flipped the handle to exceptions.
        $this->expectException(PDOException::class);
        $runner->run();
    }
}
