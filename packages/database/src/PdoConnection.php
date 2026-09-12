<?php

declare(strict_types=1);

namespace Hydra\Database;

use Hydra\Database\Contracts\ConnectionInterface;
use PDO;
use PDOException;
use Throwable;

/**
 * Wraps a configured PDO handle and prepares every statement, so all values
 * reach the driver as bound parameters — the connection has no string-built
 * SQL path. The PDO is constructed elsewhere (the service provider) so this
 * class stays driver-agnostic and trivially testable against sqlite.
 */
final class PdoConnection implements ConnectionInterface
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException) {
            // Driver doesn't support the attribute (e.g. sqlite, whose
            // prepares are always real) — tolerated, see class docblock.
        }
    }

    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): string
    {
        // PDO reports the id as a string (or false when the driver has none to
        // report); passed through uncast so UUID/string PKs and ids beyond
        // PHP_INT_MAX survive intact — see the interface docblock. The false
        // case fails loud rather than masquerading as an id.
        $id = $this->pdo->lastInsertId();

        if ($id === false) {
            throw new PDOException('The driver reported no last insert id for this connection.');
        }

        return $id;
    }

    public function transaction(callable $fn): mixed
    {
        // Re-entrant: a nested call joins the outer transaction (no savepoints).
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            // A server-side abort (deadlock, or MySQL's implicit-commit-on-DDL)
            // can leave inTransaction() false, and rollBack() would then throw
            // "no active transaction", masking the real cause. Only roll back a
            // still-live transaction; always re-throw the original throwable.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
