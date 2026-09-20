<?php

declare(strict_types=1);

namespace Hydra\Database\Validation;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * A row must exist with this value in $column. The rule for a foreign key
 * arriving from a form: a category id nobody can see is still a number a
 * client can post.
 */
final class Exists implements RuleInterface
{
    use Identifier;

    private readonly string $table;
    private readonly string $column;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $table,
        string $column,
        private readonly string $message = 'That selection is not available.',
    ) {
        $this->table = $this->assertIdentifier($table, 'table');
        $this->column = $this->assertIdentifier($column, 'column');
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_scalar($value)) {
            return $this->message;
        }

        $row = $this->connection->selectOne(
            "SELECT 1 FROM {$this->table} WHERE {$this->column} = :value LIMIT 1",
            ['value' => $value],
        );

        return $row === null ? $this->message : null;
    }
}
