<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\Testing\ConnectionContractTestCase;
use Hydra\Database\Testing\FakeConnection;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * The fake against the contract PdoConnection answers, plus the recording and
 * the failures it exists for.
 */
#[CoversClass(FakeConnection::class)]
final class FakeConnectionTest extends ConnectionContractTestCase
{
    private FakeConnection $db;

    protected function setUp(): void
    {
        $this->db = FakeConnection::inMemory();
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

    public function test_it_records_every_statement_with_its_parameters(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        $this->db->select('SELECT name FROM widgets WHERE name = :name', ['name' => 'cog']);

        $this->assertSame([
            ['sql' => 'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)', 'params' => []],
            ['sql' => 'INSERT INTO widgets (name) VALUES (?)', 'params' => ['cog']],
            ['sql' => 'SELECT name FROM widgets WHERE name = :name', 'params' => ['name' => 'cog']],
        ], $this->db->statements());
    }

    public function test_statements_inside_a_transaction_are_recorded_too(): void
    {
        $this->db->transaction(function (ConnectionInterface $db): void {
            $db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        });

        $this->db->assertRan('INSERT INTO widgets', ['cog']);
    }

    public function test_fail_on_fails_only_the_matching_statements(): void
    {
        $this->db->failOn('FROM widgets');

        $this->assertSame(1, $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connection refused');
        $this->db->select('SELECT name FROM widgets');
    }

    public function test_fail_all_fails_every_statement_with_the_given_throwable(): void
    {
        $down = new RuntimeException('gone away');
        $this->db->failAll($down);

        try {
            $this->db->selectOne('SELECT 1');
            $this->fail('The statement should have failed.');
        } catch (RuntimeException $caught) {
            $this->assertSame($down, $caught);
        }

        // Issued, even though it never reached the database: the attempt is
        // what a test about degradation is asserting on.
        $this->db->assertRan('SELECT 1');
    }

    public function test_a_failed_statement_never_reaches_the_database(): void
    {
        $this->db->failOn('INSERT');

        try {
            $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        } catch (PDOException) {
        }

        $this->assertSame([], $this->db->select('SELECT name FROM widgets'));
    }

    public function test_a_failure_inside_a_transaction_rolls_it_back(): void
    {
        $this->db->failOn('UPDATE');

        try {
            $this->db->transaction(function (ConnectionInterface $db): void {
                $db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
                $db->execute('UPDATE widgets SET name = ?', ['sprocket']);
            });
        } catch (PDOException) {
        }

        $this->assertSame([], $this->db->select('SELECT name FROM widgets'));
    }

    public function test_assert_ran_fails_on_different_parameters(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        $this->db->assertRan('INSERT INTO widgets');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No statement containing "INSERT INTO widgets" was issued with those parameters.');
        $this->db->assertRan('INSERT INTO widgets', ['sprocket']);
    }

    public function test_assert_ran_fails_when_nothing_matched(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No statement containing "DELETE" was issued.');
        $this->db->assertRan('DELETE');
    }

    public function test_assert_not_ran(): void
    {
        $this->db->assertNotRan('DELETE');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 statements containing "CREATE TABLE" were issued.');
        $this->db->assertNotRan('CREATE TABLE');
    }

    public function test_assert_statement_count(): void
    {
        $this->db->assertStatementCount(1);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected 3 statements; 1 were issued.');
        $this->db->assertStatementCount(3);
    }
}
