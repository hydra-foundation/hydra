<?php

declare(strict_types=1);

namespace Hydra\Database;

use Hydra\Core\Contracts\HealthCheckInterface;
use Hydra\Database\Contracts\ConnectionInterface;
use RuntimeException;

/** One `SELECT 1`: the connection opens and the server answers. */
final class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): void
    {
        if ($this->db->selectOne('SELECT 1 AS up') === null) {
            throw new RuntimeException('SELECT 1 returned no row.');
        }
    }
}
