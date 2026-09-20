<?php

declare(strict_types=1);

namespace Hydra\Database\Validation;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * No row may already hold this value in $column. Pass the row being edited to
 * $ignore, or every edit form fails against the row it is editing.
 *
 * A check here is advisory: two requests can both pass it before either
 * writes. The unique index on the column is what actually guarantees it, and
 * this rule exists to turn that constraint violation into a message under the
 * right input.
 */
final class Unique implements RuleInterface
{
    use Identifier;

    private readonly string $table;
    private readonly string $column;
    private readonly string $ignoreColumn;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $table,
        string $column,
        private readonly string|int|null $ignore = null,
        string $ignoreColumn = 'id',
        private readonly string $message = 'That value is already taken.',
    ) {
        $this->table = $this->assertIdentifier($table, 'table');
        $this->column = $this->assertIdentifier($column, 'column');
        $this->ignoreColumn = $this->assertIdentifier($ignoreColumn, 'column');
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $sql = "SELECT 1 FROM {$this->table} WHERE {$this->column} = :value";
        $params = ['value' => $value];

        if ($this->ignore !== null) {
            $sql .= " AND {$this->ignoreColumn} <> :ignore";
            $params['ignore'] = $this->ignore;
        }

        return $this->connection->selectOne($sql . ' LIMIT 1', $params) === null ? null : $this->message;
    }
}
