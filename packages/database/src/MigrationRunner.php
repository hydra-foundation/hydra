<?php

declare(strict_types=1);

namespace Hydra\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Applies raw .sql migration files in filename order and records which have run.
 * Forward-only by design: there are no down migrations, so recovering from a bad
 * one is a new migration rather than an undo the runner has to get right.
 */
final class MigrationRunner
{
    /** Whether the migrations table exists, asked of each driver's catalogue. */
    private const HAS_TABLE = [
        'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'migrations'",
        'mysql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'",
    ];

    /** applied_at as unix seconds, so the connection's time zone never moves it. */
    private const EPOCH = [
        'sqlite' => "CAST(strftime('%s', applied_at) AS INTEGER)",
        'mysql' => 'UNIX_TIMESTAMP(applied_at)',
    ];

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
     * The filenames applied by this call, empty when already up to date.
     *
     * @return list<string>
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
     * Execute one migration file's SQL, surfacing errors from *every* statement
     * in it, not just the first.
     */
    private function execute(string $sql): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec($sql);

            return;
        }

        // A native prepare takes one statement, so a PDO built with emulation
        // off refused every file with two in it as a syntax error.
        $emulating = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $statement = $this->pdo->query($sql);
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulating);
        }

        try {
            // Drain: each iteration surfaces the next statement's outcome.
            do {
            } while ($statement->nextRowset());
        } catch (PDOException $e) {
            // A driver that can't advance rowsets at all isn't a migration
            // failure, just a missing capability (SQLSTATE IM001). The first
            // statement's error reporting is the best it offers.
            if (!str_contains($e->getMessage(), 'does not support')) {
                throw $e;
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Drop every table, then re-apply all migrations from scratch. Destructive:
     * the calling command guards it. Returns the filenames applied.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $this->dropAllTables();

        return $this->run();
    }

    /**
     * Every migration on disk paired with whether it has been applied, in
     * order. This is the data behind migrate:status.
     *
     * @return list<array{filename: string, applied: bool}>
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
     * Migration files on disk not yet recorded as applied.
     *
     * @return list<string>
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
     * Applied, pending, and what ran last, without touching the schema: this is
     * read by a health check, and a health check that creates a table on a
     * database nobody has migrated yet is doing DDL on every refresh. With no
     * migrations table, nothing has been applied.
     *
     * The time is read as unix seconds so the connection's time zone never
     * moves it.
     */
    public function summary(): MigrationSummary
    {
        $files = $this->migrationFiles();

        if (!$this->hasTable()) {
            return new MigrationSummary([], $files, null, null);
        }

        $applied = $this->appliedFilenames();
        $epoch = self::EPOCH[$this->dialect()];

        /** @var array{filename: string, at: int|string|null}|false $last */
        $last = $this->pdo
            ->query("SELECT filename, {$epoch} AS at FROM migrations ORDER BY applied_at DESC, filename DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);

        return new MigrationSummary(
            $applied,
            array_values(array_diff($files, $applied)),
            $last === false ? null : $last['filename'],
            $last === false || $last['at'] === null
                ? null
                : (new DateTimeImmutable('@' . (int) $last['at']))->setTimezone(new DateTimeZone(date_default_timezone_get())),
        );
    }

    /** Asked of the catalogue rather than by trying a SELECT, which would hide a real fault as "never migrated". */
    private function hasTable(): bool
    {
        return $this->pdo->query(self::HAS_TABLE[$this->dialect()])->fetchColumn() !== false;
    }

    /** mysql and mariadb speak one dialect here; everything else Hydra targets is sqlite. */
    private function dialect(): string
    {
        return $this->driver === 'sqlite' ? 'sqlite' : 'mysql';
    }

    /** Written to stay portable across the mysql and sqlite drivers Hydra targets. */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
                . ' filename VARCHAR(255) NOT NULL,'
                . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' PRIMARY KEY (filename))',
        );
    }

    /** @return list<string> */
    private function appliedFilenames(): array
    {
        $statement = $this->pdo->query('SELECT filename FROM migrations ORDER BY filename');

        /** @var list<string> */
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
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
