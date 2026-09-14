<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Database\Testing\ConnectionContractTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The shipped connection against the shared contract, over a real in-memory
 * sqlite PDO, plus the one thing that is PdoConnection's own: what PDO hands
 * back from lastInsertId(), which is where its return type came from.
 */
#[CoversClass(PdoConnection::class)]
final class PdoConnectionTest extends ConnectionContractTestCase
{
    private PdoConnection $db;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db = new PdoConnection($pdo);
        $this->createWidgetsTable($this->db);
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db;
    }

    protected function createWidgetsTable(ConnectionInterface $db): void
    {
        $db->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    public function test_last_insert_id_is_the_string_pdo_reports(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

        // Regression: an int return type here would corrupt UUID and other
        // string primary keys, and 64-bit ids on 32-bit builds. The contract
        // only asks for a string; this pins the value PDO actually gives.
        $this->assertSame('2', $this->db->lastInsertId());
    }
}
