<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PdoConnection against a real (in-memory sqlite) PDO. The seam's contract:
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

    public function test_execute_returns_affected_rows_and_select_reads_them_back(): void
    {
        $affected = $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        $this->assertSame(1, $affected);

        $rows = $this->db->select('SELECT id, name FROM widgets');
        $this->assertSame([['id' => 1, 'name' => 'cog']], $rows);
    }

    public function test_select_returns_empty_array_when_no_rows(): void
    {
        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function test_select_one_returns_first_row_or_null(): void
    {
        $this->assertNull($this->db->selectOne('SELECT * FROM widgets WHERE id = ?', [99]));

        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['sprocket']);

        $this->assertSame(
            ['id' => 1, 'name' => 'sprocket'],
            $this->db->selectOne('SELECT id, name FROM widgets WHERE id = ?', [1]),
        );
    }

    public function test_last_insert_id_reflects_most_recent_insert(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

        // A string, uncast, as PDO natively reports it. Regression: an int
        // return type here would corrupt UUID/string PKs and 64-bit ids on
        // 32-bit builds; integer-PK callers cast at their own call site.
        $this->assertSame('2', $this->db->lastInsertId());
    }

    public function test_parameters_are_bound_not_interpolated(): void
    {
        // A value with SQL metacharacters round-trips intact, proving it's bound.
        $payload = "Robert'); DROP TABLE widgets;--";
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', [$payload]);

        $this->assertSame($payload, $this->db->selectOne('SELECT name FROM widgets WHERE id = 1')['name']);
    }

    public function test_transaction_commits_and_passes_the_return_value_back(): void
    {
        $id = $this->db->transaction(function (PdoConnection $db) {
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

            return $db->lastInsertId();
        });

        $this->assertSame('2', $id);
        $this->assertCount(2, $this->db->select('SELECT * FROM widgets'));
    }

    public function test_transaction_rolls_back_on_throw_and_rethrows_the_original(): void
    {
        $thrown = new RuntimeException('boom');

        try {
            $this->db->transaction(function (PdoConnection $db) use ($thrown) {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['doomed']);
                throw $thrown;
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException $caught) {
            // The exact instance, not a wrapper, so callers keep their error type.
            $this->assertSame($thrown, $caught);
        }

        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function test_transaction_invokes_the_callable_with_the_connection(): void
    {
        $this->db->transaction(function ($arg) {
            $this->assertSame($this->db, $arg);
        });
    }

    public function test_nested_transaction_joins_the_outer_one(): void
    {
        $this->db->transaction(function (PdoConnection $db) {
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['outer']);

            $db->transaction(function (PdoConnection $db) {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['inner']);
            });
        });

        $this->assertCount(2, $this->db->select('SELECT * FROM widgets'));
    }

    public function test_outer_throw_after_a_successful_inner_call_rolls_back_its_writes(): void
    {
        try {
            $this->db->transaction(function (PdoConnection $db) {
                $db->transaction(function (PdoConnection $db) {
                    $db->execute('INSERT INTO widgets (name) VALUES (?)', ['inner']);
                });

                // The inner call returned cleanly, but only the outermost call
                // owns the commit, so its failure takes the inner writes too.
                throw new RuntimeException('outer failure');
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function test_inner_throw_rolls_back_the_whole_outer_transaction(): void
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
