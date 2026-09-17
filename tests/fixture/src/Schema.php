<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture;

use PDO;

/**
 * The fixture's whole database: one table, in sqlite.
 *
 * It is declared here rather than migrated because the fixture has no
 * migrations to be the source of truth — the application it stands in for is
 * this file. An in-memory connection per test is what keeps the flows from
 * seeing each other's rows.
 */
final class Schema
{
    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::create($pdo);

        return $pdo;
    }

    public static function create(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
