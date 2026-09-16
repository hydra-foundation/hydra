<?php

declare(strict_types=1);

namespace Hydra\Admin\Sources;

use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\RowId;
use Hydra\Admin\SourceDescription;
use Hydra\Database\Contracts\ConnectionInterface;
use LogicException;

/**
 * The read side of one table, declared rather than written. Every source over a
 * plain table answers `page()` and `find()` the same way, so the difference
 * between two of them is a table name and four column lists; this is those
 * lists, with the SQL written once.
 *
 * It reads and never writes. A writable module implements the write contracts
 * itself, because write policy is the part that is never boilerplate — the rule
 * about which rows may go and what a blank field means is a fact about the
 * table, not about the admin.
 *
 * A module needing more than one table's worth of SQL — a join, a computed
 * column, a filter that is not equality — implements {@see SourceInterface}
 * directly or extends this and overrides {@see conditions()}. That escape hatch
 * is the point: this is the short way, not the only way.
 *
 * `$db` and `$table` are protected for the same reason: a subclass adding the
 * write contracts writes its own SQL against the table it already declared,
 * rather than naming it a second time.
 */
class TableSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface
{
    /**
     * Column and table names are interpolated into SQL, never bound, because an
     * identifier cannot be a parameter in any driver. They come from code rather
     * than from a request, so this is a guard against a typo reaching the
     * database as syntax, not against injection.
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var list<string> */
    private readonly array $columns;

    /**
     * @param list<string> $columns every column the admin reads, the id among them
     * @param list<string> $sortable columns a list screen may ORDER BY
     * @param list<string> $searchable columns the search box looks in
     * @param list<string> $filterable columns a toolbar filter matches exactly
     */
    public function __construct(
        protected readonly ConnectionInterface $db,
        protected readonly string $table,
        array $columns,
        private readonly array $sortable,
        private readonly array $searchable = [],
        private readonly array $filterable = [],
        private readonly string $defaultSort = 'id',
    ) {
        $this->columns = array_values($columns);

        $this->guard();
    }

    /**
     * The declaration, handed back so something outside can check it against a
     * module's fields. Nothing at runtime reads this: the SQL is built from the
     * same properties directly, and a screen never asks a source what it is.
     * It exists for `admin:check`, and for a test that wants to state the
     * pairing rather than assume it.
     */
    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: $this->table,
            columns: $this->columns,
            sortable: array_values($this->sortable),
            searchable: array_values($this->searchable),
            filterable: array_values($this->filterable),
            defaultSort: $this->defaultSort,
        );
    }

    public function find(string $id): ?array
    {
        $key = RowId::int($id);

        if ($key === null) {
            return null;
        }

        return $this->db->selectOne(
            sprintf('SELECT %s FROM %s WHERE id = ?', $this->columnList(), $this->table),
            [$key],
        );
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, $this->sortable, true) ? $criteria->sort : $this->defaultSort;

        $total = $this->db->selectOne(
            sprintf('SELECT COUNT(*) AS total FROM %s WHERE %s', $this->table, $where),
            $params,
        );

        return new Page(
            $this->db->select(
                sprintf(
                    'SELECT %s FROM %s WHERE %s ORDER BY %s %s LIMIT %d OFFSET %d',
                    $this->columnList(),
                    $this->table,
                    $where,
                    $order,
                    $criteria->direction,
                    $criteria->perPage,
                    $criteria->offset(),
                ),
                $params,
            ),
            (int) ($total['total'] ?? 0),
            $criteria,
        );
    }

    /**
     * The WHERE body and its bound values. Always a body — an unfiltered list
     * says `1=1` rather than dropping the keyword, so every query this builds
     * has the same shape.
     *
     * Override to add a condition the declaration cannot express, and call
     * `parent::conditions()` for the search and filter clauses.
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    protected function conditions(Criteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->search !== null && $this->searchable !== []) {
            $clauses[] = '(' . implode(' OR ', array_map(
                static fn (string $column): string => Criteria::like($column),
                $this->searchable,
            )) . ')';

            foreach ($this->searchable as $_) {
                $params[] = $criteria->searchPattern();
            }
        }

        foreach ($this->filterable as $column) {
            if (isset($criteria->filters[$column])) {
                $clauses[] = $column . ' = ?';
                $params[] = $criteria->filters[$column];
            }
        }

        return [$clauses === [] ? '1=1' : implode(' AND ', $clauses), $params];
    }

    private function columnList(): string
    {
        return implode(', ', $this->columns);
    }

    /**
     * A malformed declaration is a mistake in code, so it is raised where the
     * source is built rather than on the request that would have run the SQL.
     * The list checks are what make the filter key and the SQL column one thing
     * instead of two that have to agree: a filter naming a column the table does
     * not select is the mistake that otherwise returns every row, unfiltered and
     * unremarked.
     */
    private function guard(): void
    {
        if (!preg_match(self::IDENTIFIER, $this->table)) {
            throw new LogicException(sprintf('%s: "%s" is not a table name.', static::class, $this->table));
        }

        if ($this->columns === []) {
            throw new LogicException(sprintf('%s: a source reads at least one column.', static::class));
        }

        foreach ($this->columns as $column) {
            if (!preg_match(self::IDENTIFIER, $column)) {
                throw new LogicException(sprintf('%s: "%s" is not a column name.', static::class, $column));
            }
        }

        $lists = [
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'defaultSort' => [$this->defaultSort],
        ];

        foreach ($lists as $name => $columns) {
            foreach ($columns as $column) {
                if (!in_array($column, $this->columns, true)) {
                    throw new LogicException(sprintf(
                        '%s: %s names "%s", which is not a column it reads.',
                        static::class,
                        $name,
                        $column,
                    ));
                }
            }
        }
    }
}
