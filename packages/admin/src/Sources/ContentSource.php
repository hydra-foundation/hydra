<?php

declare(strict_types=1);

namespace Hydra\Admin\Sources;

use Closure;
use DateTimeInterface;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\View\Content\ContentDirectory;
use Hydra\View\Content\ContentFile;
use LogicException;

/**
 * A directory of Markdown files as rows, declared the way a TableSource is:
 * which columns, and which of them sort, search and filter. The files live in
 * git and change there, so this only reads.
 *
 * A row is a file's front matter plus `id` and `slug` (the file name), `body`
 * (the Markdown below the front matter), `modified_at` and `problem` (why its
 * front matter couldn't be read, or null). Those five are always there and
 * need no declaring. A broken file is still a row, with only those five, so
 * one bad post never hides the others.
 *
 * Everything happens in memory, over every file: fine for the few hundred
 * posts a site has, and there is no index to fall out of step with the
 * directory. A list page leaves `body` out unless it is a declared column.
 *
 * `map:` adds what the site derives, such as a post's status. It sees the row
 * as read (dates as DateTimeImmutable, lists as lists) and is not called for
 * a broken file; what it returns is searched, sorted and filtered like the
 * rest. Only then is the row flattened for printing: a date becomes ISO 8601,
 * a list is joined with ", ".
 */
class ContentSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface
{
    /** What every row has, whatever its front matter says. */
    private const ALWAYS = ['id', 'slug', 'body', 'modified_at', 'problem'];

    /** @var list<string> every column a row has: the five above and the declared ones */
    private readonly array $columns;

    /** Whether a list page carries the body: only when a column asks for it. */
    private readonly bool $listsBody;

    /**
     * @param list<string> $columns the front-matter keys (and map: columns) a screen shows
     * @param list<string> $sortable columns a list screen may sort by
     * @param list<string> $searchable columns the search box looks in
     * @param list<string> $filterable columns a toolbar filter matches (a list
     *                                  column by containing the value), or narrows
     *                                  by day when the field is a date or datetime
     * @param (Closure(array<string, mixed>): array<string, mixed>)|null $map
     */
    public function __construct(
        private readonly ContentDirectory $directory,
        array $columns,
        private readonly array $sortable = [],
        private readonly array $searchable = [],
        private readonly array $filterable = [],
        private readonly string $defaultSort = 'slug',
        private readonly ?Closure $map = null,
    ) {
        $this->columns = array_values(array_unique(['id', 'slug', ...$columns, ...self::ALWAYS]));
        $this->listsBody = in_array('body', $columns, true);

        $this->guard();
    }

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: $this->directory->directory,
            columns: $this->columns,
            sortable: array_values($this->sortable),
            searchable: array_values($this->searchable),
            filterable: array_values($this->filterable),
            defaultSort: $this->defaultSort,
        );
    }

    public function find(string $id): ?array
    {
        $file = $this->directory->find($id);

        return $file === null ? null : self::flatten($this->row($file));
    }

    public function page(Criteria $criteria): Page
    {
        $rows = array_values(array_filter(
            array_map($this->row(...), $this->directory->all()),
            fn (array $row): bool => $this->matches($row, $criteria),
        ));

        $this->sort($rows, $criteria);
        $withBody = $this->listsBody;

        return new Page(
            array_map(static function (array $row) use ($withBody): array {
                if (!$withBody) {
                    unset($row['body']);
                }

                return self::flatten($row);
            }, array_slice($rows, $criteria->offset(), $criteria->perPage)),
            count($rows),
            $criteria,
        );
    }

    /** @return array<string, mixed> the file as read, map: applied */
    private function row(ContentFile $file): array
    {
        $row = [
            ...$file->meta,
            'id' => $file->slug,
            'slug' => $file->slug,
            'body' => $file->body,
            'modified_at' => $file->modifiedAt,
            'problem' => $file->error?->getMessage(),
        ];

        return $this->map === null || $file->error !== null ? $row : ($this->map)($row);
    }

    /** @param array<string, mixed> $row */
    private function matches(array $row, Criteria $criteria): bool
    {
        foreach ($this->filterable as $column) {
            $value = $row[$column] ?? null;

            if (isset($criteria->ranges[$column])) {
                if (!$value instanceof DateTimeInterface || !$criteria->ranges[$column]->contains($value)) {
                    return false;
                }
            } elseif (isset($criteria->filters[$column])) {
                $wanted = $criteria->filters[$column];
                $values = is_array($value) && array_is_list($value) ? $value : [$value];

                if (!in_array($wanted, array_map(self::text(...), $values), true)) {
                    return false;
                }
            }
        }

        if ($criteria->search === null) {
            return true;
        }

        foreach ($this->searchable as $column) {
            if (mb_stripos(self::text($row[$column] ?? null), $criteria->search) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sort(array &$rows, Criteria $criteria): void
    {
        $column = in_array($criteria->sort, $this->sortable, true) ? (string) $criteria->sort : $this->defaultSort;

        usort($rows, static function (array $a, array $b) use ($column, $criteria): int {
            $order = self::compare($a[$column] ?? null, $b[$column] ?? null);
            // The slug breaks a tie, so a page boundary never moves between requests.
            $order = $order !== 0 ? $order : strcmp((string) $a['slug'], (string) $b['slug']);

            return $criteria->direction === 'desc' ? -$order : $order;
        });
    }

    /** Nothing first, then dates and numbers as such, and text naturally. */
    private static function compare(mixed $a, mixed $b): int
    {
        return match (true) {
            $a === null || $b === null => ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1),
            $a instanceof DateTimeInterface && $b instanceof DateTimeInterface,
            (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) => $a <=> $b,
            default => strnatcasecmp(self::text($a), self::text($b)),
        };
    }

    /** A value as a filter, a search or a sort sees it. */
    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => (string) self::printable($value),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function flatten(array $row): array
    {
        return array_map(self::printable(...), $row);
    }

    /** A value as a screen prints it: a scalar as it is, a date as ISO 8601, a list joined. */
    private static function printable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value) && array_filter($value, static fn (mixed $item): bool => !is_scalar($item) && !$item instanceof DateTimeInterface) === []) {
            return implode(', ', array_map(self::text(...), $value));
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A list naming a column the source doesn't read is a mistake in code,
     * raised where the source is built: a filter on a misspelt key would
     * otherwise match nothing, or everything, without a word.
     */
    private function guard(): void
    {
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
