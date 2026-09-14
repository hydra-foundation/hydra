<?php

declare(strict_types=1);

namespace Hydra\Database\Testing;

use Hydra\Database\Contracts\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The behaviour every connection owes its callers, published so a driver the
 * framework does not ship can be held to it.
 *
 * A second driver is the likeliest thing a user writes against these contracts,
 * and the interface is narrow precisely so that writing one is reasonable. What
 * it cannot state in its signatures is the part that costs most to get wrong:
 * that values are bound rather than interpolated, and that a nested
 * transaction() joins the outermost call instead of opening one of its own, so
 * an inner success still rolls back when the outer call fails later.
 *
 * The SQL below is deliberately the boring intersection of every dialect. The
 * one place they refuse to agree is the spelling of an autoincrementing key, so
 * that is the schema hook rather than a statement in the case.
 */
abstract class ConnectionContractTestCase extends TestCase
{
    abstract protected function connection(): ConnectionInterface;

    /**
     * Create the one table these tests use: `widgets`, with an autoincrementing
     * integer `id` primary key and a `name` text column that is NOT NULL.
     */
    abstract protected function createWidgetsTable(ConnectionInterface $db): void;

    /** @return list<string> the names in the table, in insertion order */
    private function names(ConnectionInterface $db): array
    {
        return array_column($db->select('SELECT name FROM widgets ORDER BY id'), 'name');
    }

    private function insert(ConnectionInterface $db, string $name): string
    {
        $db->execute('INSERT INTO widgets (name) VALUES (?)', [$name]);

        return $db->lastInsertId();
    }

    public function test_execute_returns_affected_rows_and_select_reads_them_back(): void
    {
        $db = $this->connection();

        $this->assertSame(1, $db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']));
        $this->assertSame(['cog'], $this->names($db));
    }

    public function test_execute_reports_rows_an_update_touched(): void
    {
        // Not just inserts: a caller deciding whether anything was found relies
        // on this count, and returning 1-for-success would read as "found".
        $db = $this->connection();
        $this->insert($db, 'a');
        $this->insert($db, 'b');

        $this->assertSame(2, $db->execute('UPDATE widgets SET name = ?', ['same']));
        $this->assertSame(0, $db->execute('UPDATE widgets SET name = ? WHERE name = ?', ['x', 'absent']));
    }

    public function test_execute_reports_rows_a_delete_removed(): void
    {
        $db = $this->connection();
        $this->insert($db, 'a');

        $this->assertSame(1, $db->execute('DELETE FROM widgets WHERE name = ?', ['a']));
        $this->assertSame(0, $db->execute('DELETE FROM widgets WHERE name = ?', ['a']));
    }

    public function test_select_returns_an_empty_list_when_nothing_matches(): void
    {
        // Empty, not null and not false: every caller iterates the result.
        $this->assertSame([], $this->connection()->select('SELECT * FROM widgets'));
    }

    public function test_select_one_returns_the_first_row_or_null(): void
    {
        $db = $this->connection();

        $this->assertNull($db->selectOne('SELECT * FROM widgets WHERE name = ?', ['absent']));

        $this->insert($db, 'sprocket');
        $row = $db->selectOne('SELECT name FROM widgets WHERE name = ?', ['sprocket']);

        $this->assertSame(['name' => 'sprocket'], $row);
    }

    public function test_rows_are_keyed_by_column_name(): void
    {
        // Associative only. A numerically-indexed row would still pass a count
        // assertion while breaking every caller that reads a column.
        $db = $this->connection();
        $this->insert($db, 'cog');

        $row = $db->select('SELECT name FROM widgets')[0];

        $this->assertSame(['name'], array_keys($row));
    }

    public function test_positional_parameters_are_bound_not_interpolated(): void
    {
        // A value full of SQL metacharacters round-trips intact, which it only
        // can if it was never part of the statement text.
        $db = $this->connection();
        $payload = "Robert'); DROP TABLE widgets;--";
        $this->insert($db, $payload);

        $this->assertSame([$payload], $this->names($db));
    }

    public function test_named_parameters_are_bound_too(): void
    {
        // The signature accepts a string-keyed array, and nothing else in the
        // tree proves a driver honours one.
        $db = $this->connection();
        $db->execute('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'cog']);

        $this->assertSame(
            ['name' => 'cog'],
            $db->selectOne('SELECT name FROM widgets WHERE name = :name', ['name' => 'cog']),
        );
    }

    public function test_a_null_parameter_binds_as_null(): void
    {
        // `scalar|null` is in the signature, and a driver that stringified null
        // would match the literal 'name' instead of matching nothing.
        $db = $this->connection();
        $this->insert($db, 'cog');

        $this->assertSame([], $db->select('SELECT name FROM widgets WHERE name = ?', [null]));
    }

    public function test_last_insert_id_identifies_the_row_just_inserted(): void
    {
        $db = $this->connection();
        $first = $this->insert($db, 'a');
        $second = $this->insert($db, 'b');

        // The string return type is the interface's to enforce; what it cannot
        // is that the value identifies this row. It is a string because UUID and
        // other non-integer keys are legitimate and an integer id can exceed
        // PHP_INT_MAX, so callers on integer keys cast at their own call site.
        $this->assertNotSame($first, $second);
        $this->assertSame(
            ['name' => 'b'],
            $db->selectOne('SELECT name FROM widgets WHERE id = ?', [$second]),
        );
    }

    public function test_transaction_commits_and_passes_the_return_value_back(): void
    {
        $db = $this->connection();

        $names = $db->transaction(function (ConnectionInterface $db): array {
            $this->insert($db, 'a');
            $this->insert($db, 'b');

            return $this->names($db);
        });

        $this->assertSame(['a', 'b'], $names);
        $this->assertSame(['a', 'b'], $this->names($db));
    }

    public function test_transaction_invokes_the_callable_with_the_connection(): void
    {
        $db = $this->connection();
        $seen = null;

        $db->transaction(function ($arg) use (&$seen): void {
            $seen = $arg;
        });

        $this->assertSame($db, $seen);
    }

    public function test_transaction_rolls_back_on_throw_and_rethrows_the_original(): void
    {
        $db = $this->connection();
        $thrown = new RuntimeException('boom');

        try {
            $db->transaction(function (ConnectionInterface $db) use ($thrown): void {
                $this->insert($db, 'doomed');

                throw $thrown;
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException $caught) {
            // The exact instance, not a wrapper, so callers keep their error type.
            $this->assertSame($thrown, $caught);
        }

        $this->assertSame([], $this->names($db));
    }

    public function test_nested_transaction_joins_the_outer_one(): void
    {
        $db = $this->connection();

        $db->transaction(function (ConnectionInterface $db): void {
            $this->insert($db, 'outer');

            $db->transaction(function (ConnectionInterface $db): void {
                $this->insert($db, 'inner');
            });
        });

        $this->assertSame(['outer', 'inner'], $this->names($db));
    }

    public function test_an_outer_throw_takes_a_successful_inner_call_with_it(): void
    {
        $db = $this->connection();

        try {
            $db->transaction(function (ConnectionInterface $db): void {
                $db->transaction(function (ConnectionInterface $db): void {
                    $this->insert($db, 'inner');
                });

                // The inner call returned cleanly, but only the outermost call
                // owns the commit, so its failure takes the inner writes too.
                throw new RuntimeException('outer failure');
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->names($db));
    }

    public function test_an_inner_throw_rolls_back_the_whole_outer_transaction(): void
    {
        $db = $this->connection();

        try {
            $db->transaction(function (ConnectionInterface $db): void {
                $this->insert($db, 'outer');

                $db->transaction(function (): void {
                    throw new RuntimeException('inner failure');
                });
            });
            $this->fail('The throwable should have propagated.');
        } catch (RuntimeException) {
        }

        // Atomic: the outer insert rolled back with the inner failure.
        $this->assertSame([], $this->names($db));
    }
}
