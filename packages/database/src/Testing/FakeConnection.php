<?php

declare(strict_types=1);

namespace Hydra\Database\Testing;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A connection that records every statement and can be told to fail, for
 * asserting on.
 *
 * The statements still run. SQL cannot be faked honestly by anything short of
 * a database, so this sits in front of a real connection, in-memory SQLite
 * unless another is given, and a test gets real rows back. What it adds is the
 * two things a real connection cannot do on demand: say what it was asked, and
 * go down.
 */
final class FakeConnection implements ConnectionInterface
{
    /** @var list<array{sql: string, params: array<string|int, scalar|null>}> */
    private array $statements = [];

    /** @var list<array{contains: string|null, with: Throwable}> */
    private array $failures = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public static function inMemory(): self
    {
        return new self(new PdoConnection(new PDO('sqlite::memory:')));
    }

    /** Every statement containing $sql throws $with, before it reaches the database. */
    public function failOn(string $sql, ?Throwable $with = null): self
    {
        $this->failures[] = ['contains' => $sql, 'with' => $with ?? new PDOException('Connection refused')];

        return $this;
    }

    /** Every statement throws $with, as a database that is down would. */
    public function failAll(?Throwable $with = null): self
    {
        $this->failures[] = ['contains' => null, 'with' => $with ?? new PDOException('Connection refused')];

        return $this;
    }

    public function select(string $sql, array $params = []): array
    {
        $this->record($sql, $params);

        return $this->connection->select($sql, $params);
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $this->record($sql, $params);

        return $this->connection->selectOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->record($sql, $params);

        return $this->connection->execute($sql, $params);
    }

    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        return $this->connection->transaction(fn (): mixed => $fn($this));
    }

    /**
     * Every statement issued, oldest first, including those made to fail.
     *
     * @return list<array{sql: string, params: array<string|int, scalar|null>}>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * A statement containing $sql was issued, and with exactly these bound
     * values when they are given.
     *
     * @param array<string|int, scalar|null>|null $params
     */
    public function assertRan(string $sql, ?array $params = null): void
    {
        $matching = array_filter(
            $this->statements,
            static fn (array $statement): bool => str_contains($statement['sql'], $sql)
                && ($params === null || $statement['params'] === $params),
        );

        $message = $params === null
            ? "No statement containing \"{$sql}\" was issued."
            : "No statement containing \"{$sql}\" was issued with those parameters.";

        Assert::assertNotEmpty($matching, $message);
    }

    public function assertNotRan(string $sql): void
    {
        $count = count(array_filter(
            $this->statements,
            static fn (array $statement): bool => str_contains($statement['sql'], $sql),
        ));

        Assert::assertSame(0, $count, "{$count} statements containing \"{$sql}\" were issued.");
    }

    /** Exactly $count statements were issued, which is how an N+1 shows up in a test. */
    public function assertStatementCount(int $count): void
    {
        $issued = count($this->statements);

        Assert::assertSame($count, $issued, "Expected {$count} statements; {$issued} were issued.");
    }

    /** @param array<string|int, scalar|null> $params */
    private function record(string $sql, array $params): void
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];

        foreach ($this->failures as $failure) {
            if ($failure['contains'] === null || str_contains($sql, $failure['contains'])) {
                throw $failure['with'];
            }
        }
    }
}
