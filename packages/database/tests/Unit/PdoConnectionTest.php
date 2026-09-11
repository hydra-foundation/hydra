<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PdoConnection against a real (in-memory sqlite) PDO — the seam's contract:
 * prepared select/selectOne/execute and lastInsertId, no driver-specific code.
 */
final class PdoConnectionTest extends TestCase
{
    private PdoConnection $db;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $this->db = new PdoConnection($pdo);
    }

    public function testExecuteReturnsAffectedRowsAndSelectReadsThemBack(): void
    {
        $affected = $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        $this->assertSame(1, $affected);

        $rows = $this->db->select('SELECT id, name FROM widgets');
        $this->assertSame([['id' => 1, 'name' => 'cog']], $rows);
    }

    public function testSelectReturnsEmptyArrayWhenNoRows(): void
    {
        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function testSelectOneReturnsFirstRowOrNull(): void
    {
        $this->assertNull($this->db->selectOne('SELECT * FROM widgets WHERE id = ?', [99]));

        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['sprocket']);

        $this->assertSame(
            ['id' => 1, 'name' => 'sprocket'],
            $this->db->selectOne('SELECT id, name FROM widgets WHERE id = ?', [1]),
        );
    }

    public function testLastInsertIdReflectsMostRecentInsert(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

        // A string, uncast — PDO's native surface. Regression: an int return
        // type here would corrupt UUID/string PKs and 64-bit ids on 32-bit
        // builds; integer-PK callers cast at their own call site.
        $this->assertSame('2', $this->db->lastInsertId());
    }

    public function testParametersAreBoundNotInterpolated(): void
    {
        // A value with SQL metacharacters round-trips intact — proof it's bound.
        $payload = "Robert'); DROP TABLE widgets;--";
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', [$payload]);

        $this->assertSame($payload, $this->db->selectOne('SELECT name FROM widgets WHERE id = 1')['name']);
    }

    public function testTransactionCommitsAndPassesTheReturnValueBack(): void
    {
        $id = $this->db->transaction(function (PdoConnection $db) {
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

            return $db->lastInsertId();
        });

        $this->assertSame('2', $id);
        $this->assertCount(2, $this->db->select('SELECT * FROM widgets'));
    }

    public function testTransactionRollsBackOnThrowAndRethrowsTheOriginal(): void
    {
        $thrown = new RuntimeException('boom');

        try {
            $this->db->transaction(function (PdoConnection $db) use ($thrown) {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['doomed']);
                throw $thrown;
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException $caught) {
            // The exact instance, not a wrapper — callers keep their error type.
            $this->assertSame($thrown, $caught);
        }

        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function testTransactionInvokesTheCallableWithTheConnection(): void
    {
        $this->db->transaction(function ($arg) {
            $this->assertSame($this->db, $arg);
        });
    }

    public function testNestedTransactionJoinsTheOuterOne(): void
    {
        $this->db->transaction(function (PdoConnection $db) {
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['outer']);

            $db->transaction(function (PdoConnection $db) {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['inner']);
            });
        });

        $this->assertCount(2, $this->db->select('SELECT * FROM widgets'));
    }

    public function testOuterThrowAfterASuccessfulInnerCallRollsBackItsWrites(): void
    {
        try {
            $this->db->transaction(function (PdoConnection $db) {
                $db->transaction(function (PdoConnection $db) {
                    $db->execute('INSERT INTO widgets (name) VALUES (?)', ['inner']);
                });

                // The inner call returned cleanly, but only the outermost call
                // owns the commit — its failure takes the inner writes with it.
                throw new RuntimeException('outer failure');
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function testInnerThrowRollsBackTheWholeOuterTransaction(): void
    {
        try {
            $this->db->transaction(function (PdoConnection $db) {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['outer']);

                $db->transaction(function () {
                    throw new RuntimeException('inner failure');
                });
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException) {
        }

        // Atomic: the outer insert rolled back with the inner failure.
        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }
}
