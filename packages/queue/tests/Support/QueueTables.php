<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Support;

use Hydra\Database\Contracts\ConnectionInterface;

final class QueueTables
{
    public static function create(ConnectionInterface $db): void
    {
        $db->execute(
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER NULL,
                reservation CHAR(32) NULL,
                created_at INTEGER NOT NULL
            )',
        );
        $db->execute(
            'CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )',
        );
    }
}
