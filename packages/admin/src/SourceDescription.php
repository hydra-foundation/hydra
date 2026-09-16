<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * What a source says it reads, sorts, searches and filters by.
 *
 * A source is free not to answer this at all — {@see SourceInterface} asks only
 * for a page of rows, and a source over a join or a remote service has no column
 * list to give. What a description buys is the one check the framework otherwise
 * cannot make: a module's `->filterable()` field and its source's filterable
 * column are the same string in two files, and nothing reconciles them. The
 * declaration inside the source is guarded against itself; this is what lets
 * something outside compare the two.
 *
 * It is deliberately a statement of intent and not a schema. The columns here
 * are what the source admits to reading, which is not always what the table
 * holds — `UserSource` writes `password_hash` and never reads it back, and that
 * absence is the point rather than an omission.
 */
final readonly class SourceDescription
{
    /**
     * @param list<string> $columns every column the source reads
     * @param list<string> $sortable columns a list screen may ORDER BY
     * @param list<string> $searchable columns the search box looks in
     * @param list<string> $filterable columns a toolbar filter matches exactly
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $sortable,
        public array $searchable,
        public array $filterable,
        public string $defaultSort,
    ) {}

    public function reads(string $column): bool
    {
        return in_array($column, $this->columns, true);
    }

    public function sortsBy(string $column): bool
    {
        return in_array($column, $this->sortable, true);
    }

    public function searches(string $column): bool
    {
        return in_array($column, $this->searchable, true);
    }

    public function filtersBy(string $column): bool
    {
        return in_array($column, $this->filterable, true);
    }
}
