<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Http\Query;

/**
 * A list screen's request state: which page, which order, which filters. The
 * constructor normalises what a source interpolates rather than binds; only
 * fromQuery() can whitelist sort and filter keys against the blueprint.
 */
final readonly class Criteria
{
    /**
     * The furthest page a request may ask for.
     *
     * page becomes an OFFSET, and a large one makes the database walk every
     * row it skips — so an unbounded page number is an unauthenticated way to
     * turn one cheap request into a full table scan. This is a blast-radius
     * cap, not a correctness bound: a list with fewer pages still clamps to its
     * own last page when the total is known.
     */
    public const MAX_PAGE = 10_000;

    /** Longer than any real search, and short enough to stay cheap to match. */
    public const MAX_SEARCH = 128;

    public int $page;
    public int $perPage;
    public ?string $sort;
    public string $direction;

    /** @var array<string, string> */
    public array $filters;

    public ?string $search;

    /** @param array<string, string> $filters */
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        ?string $sort = null,
        string $direction = 'asc',
        array $filters = [],
        ?string $search = null,
    ) {
        $this->page = min(max(1, $page), self::MAX_PAGE);
        $this->perPage = max(1, $perPage);
        $this->sort = $sort;
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->filters = $filters;
        // Normalised rather than refused, like page above: a list filter that
        // errors on a long paste is worse for the reader than one that matches
        // on as much of it as could ever be useful.
        $this->search = $search === null ? null : mb_substr($search, 0, self::MAX_SEARCH);
    }

    /** The view of a list nobody has asked anything of: the module's own defaults. */
    public static function defaults(Blueprint $blueprint): self
    {
        return new self(
            perPage: $blueprint->perPage,
            sort: $blueprint->defaultSort,
            direction: $blueprint->defaultDirection,
        );
    }

    public static function fromQuery(Query $query, Blueprint $blueprint): self
    {
        $sortable = array_map(static fn (Field $field): string => $field->name(), $blueprint->sortable());
        $requested = $query->string('sort');
        $sort = in_array($requested, $sortable, true) ? $requested : $blueprint->defaultSort;

        $filters = [];

        foreach ($blueprint->filterable() as $field) {
            $value = $query->string($field->name());
            $options = $field->options();

            if ($value !== '' && ($options === null || array_key_exists($value, $options))) {
                $filters[$field->name()] = $value;
            }
        }

        $direction = strtolower($query->string('dir'));
        $search = trim($query->string('q'));

        return new self(
            page: $query->int('page', 1) ?? 1,
            perPage: $blueprint->perPage,
            sort: $sort,
            direction: in_array($direction, ['asc', 'desc'], true) ? $direction : $blueprint->defaultDirection,
            filters: $filters,
            search: $search === '' ? null : $search,
        );
    }

    /** The same list, at another page. */
    public function onPage(int $page): self
    {
        return new self(
            page: $page,
            perPage: $this->perPage,
            sort: $this->sort,
            direction: $this->direction,
            filters: $this->filters,
            search: $this->search,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The search term as a LIKE pattern, or null when nothing was searched for.
     *
     * The term's own wildcards are escaped first. They are not an injection
     * risk — the pattern is always bound as a parameter — but they are a cost
     * one: a bare "%" matches every row, which turns a search box into a way to
     * ask for a full scan on demand. Escaping is done here rather than in each
     * source so that no source can forget, and so the SQL stays one shape.
     *
     * The escape character is a backslash, and the LIKE that consumes this has
     * to name it: `LIKE ? ESCAPE '\'`. Only MySQL/MariaDB assume a backslash on
     * their own, and only while NO_BACKSLASH_ESCAPES is off — SQLite assumes
     * none at all, so without the clause an escaped term matches nothing.
     */
    public function searchPattern(): ?string
    {
        if ($this->search === null) {
            return null;
        }

        return '%' . addcslashes($this->search, '%_\\') . '%';
    }

    /** @return array<string, string> */
    public function toQuery(): array
    {
        $params = [
            'q' => $this->search,
            'sort' => $this->sort,
            'dir' => $this->sort === null ? null : $this->direction,
            'page' => $this->page > 1 ? (string) $this->page : null,
        ];

        return array_filter([...$params, ...$this->filters], static fn (?string $value): bool => $value !== null);
    }
}
