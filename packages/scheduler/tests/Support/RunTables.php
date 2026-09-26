<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Support;

use Hydra\Database\Contracts\ConnectionInterface;

final class RunTables
{
    public static function create(ConnectionInterface $db): void
    {
        $db->execute(
            'CREATE TABLE scheduled_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task VARCHAR(255) NOT NULL,
                outcome VARCHAR(16) NOT NULL,
                items INTEGER NULL,
                held_minutes INTEGER NULL,
                error TEXT NULL,
                started_at INTEGER NOT NULL,
                duration_ms INTEGER NULL
            )',
        );
    }
}
