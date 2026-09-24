<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\DatabaseHealthCheck;
use Hydra\Database\Testing\FakeConnection;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseHealthCheck::class)]
final class DatabaseHealthCheckTest extends TestCase
{
    public function test_a_database_that_answers_passes_with_one_statement(): void
    {
        $db = FakeConnection::inMemory();
        $check = new DatabaseHealthCheck($db);

        $check->check();

        $this->assertSame('database', $check->name());
        $db->assertStatementCount(1);
    }

    public function test_a_database_that_refuses_fails_with_its_reason(): void
    {
        $db = FakeConnection::inMemory()->failAll(new PDOException('Connection refused'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Connection refused');

        (new DatabaseHealthCheck($db))->check();
    }

    public function test_no_row_back_is_a_failure(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('selectOne')->willReturn(null);

        $this->expectException(RuntimeException::class);

        (new DatabaseHealthCheck($db))->check();
    }
}
