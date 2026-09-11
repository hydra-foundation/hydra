<?php

declare(strict_types=1);

namespace Hydra\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Migration Runner
 *
 * Applies raw .sql migration files and records which have run
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        private readonly string $driver,
    ) {
        // "Record only after success" is only as strong as the error mode:
        // under ERRMODE_SILENT a failed statement returns false and the runner
        // would happily mark the migration applied. Enforce exceptions here
        // rather than trusting whoever built the PDO.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Apply every pending migration in order and return the filenames applied
     * during this call (empty when already up to date)
     */
    public function run(): array
    {
        $this->ensureTable();

        $applied = [];
        foreach ($this->pending() as $filename) {
            $path = $this->migrationsPath . '/' . $filename;
            $sql = @file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration file: {$path}");
            }

            // Order matters: execute first, record only once the SQL ran
            // without error. An exception here aborts the loop, so later
            // pending files don't run on top of a broken schema either.
            $this->execute($sql);
            $this->record($filename);
            $applied[] = $filename;
        }

        return $applied;
    }

    /**
     * Execute one migration file's SQL, surfacing errors from *every* statement in it
     */
    private function execute(string $sql): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec($sql);

            return;
        }

        $statement = $this->pdo->query($sql);

        try {
            // Drain: each iteration surfaces the next statement's outcome.
            do {
            } while ($statement->nextRowset());
        } catch (PDOException $e) {
            // A driver that can't advance rowsets at all isn't a migration
            // failure, just a missing capability (SQLSTATE IM001) — the
            // first statement's error reporting is the best it offers.
            if (!str_contains($e->getMessage(), 'does not support')) {
                throw $e;
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Drop every table, then re-apply all migrations from scratch.
     * Destructive — the calling command guards it. Returns the filenames applied.
     */
    public function fresh(): array
    {
        $this->dropAllTables();

        return $this->run();
    }

    /**
     * Every migration on disk paired with whether it has been applied, in
     * order — the data behind migrate:status.
     */
    public function status(): array
    {
        $this->ensureTable();

        $applied = $this->appliedFilenames();

        return array_map(
            static fn (string $filename): array => [
                'filename' => $filename,
                'applied' => in_array($filename, $applied, true),
            ],
            $this->migrationFiles(),
        );
    }

    /**
     * Migration files on disk that have not yet been recorded as applied
     */
    public function pending(): array
    {
        $this->ensureTable();

        $applied = $this->appliedFilenames();

        return array_values(array_filter(
            $this->migrationFiles(),
            static fn (string $filename): bool => !in_array($filename, $applied, true),
        ));
    }

    /**
     * Create the tracking table if it does not exist.
     * Portable across the mysql and sqlite drivers Hydra targets.
     */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
                . ' filename VARCHAR(255) NOT NULL,'
                . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' PRIMARY KEY (filename))',
        );
    }

    private function appliedFilenames(): array
    {
        $statement = $this->pdo->query('SELECT filename FROM migrations ORDER BY filename');

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = array_map('basename', glob($this->migrationsPath . '/*.sql') ?: []);
        sort($files);

        return $files;
    }

    private function record(string $filename): void
    {
        $statement = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $statement->execute([$filename]);
    }

    /**
     * Drop every table in the current database. Driver-aware: MariaDB enumerates
     * via information_schema and toggles FK checks; sqlite uses sqlite_master and
     * a PRAGMA. Migrations target MariaDB, but the sqlite branch keeps fresh()
     * exercisable by the test suite.
     */
    private function dropAllTables(): void
    {
        if ($this->driver === 'sqlite') {
            $tables = $this->pdo
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS "' . $table . '"');
            }
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            return;
        }

        $tables = $this->pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
