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
     * row it skips, so an unbounded page number is an unauthenticated way to
     * turn one cheap request into a full table scan. This is a blast-radius
     * cap, not a correctness bound: a list with fewer pages still clamps to its
     * own last page when the total is known.
     */
    public const MAX_PAGE = 10_000;

    /** Longer than any real search, and short enough to stay cheap to match. */
    public const MAX_SEARCH = 128;

    /**
     * The character that escapes a wildcard inside a search pattern.
     *
     * Not a backslash: MySQL/MariaDB read one inside a string literal as an
     * escape of its own, so `ESCAPE '\'` is an unterminated literal there, and
     * the doubled form it needs instead is two characters to SQLite. A
     * character neither dialect touches spells the same in both.
     */
    public const SEARCH_ESCAPE = '!';

    public int $page;
    public int $perPage;
    public ?string $sort;
    public string $direction;

    /** @var array<string, string> */
    public array $filters;

    public ?string $search;

    /**
     * The filter link this view sits on, or null when the list is being read
     * without one. Its own filters are folded into $filters below, so a source
     * answers a link without ever being told one was clicked.
     */
    public ?Link $view;

    /** @param array<string, string> $filters filters asked for on top of $view */
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        ?string $sort = null,
        string $direction = 'asc',
        array $filters = [],
        ?string $search = null,
        ?Link $view = null,
    ) {
        $this->page = min(max(1, $page), self::MAX_PAGE);
        $this->perPage = max(1, $perPage);
        $this->sort = $sort;
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->view = $view;
        $this->filters = [...$view?->filters() ?? [], ...$filters];
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

    /**
     * $pinned forces a filter link on regardless of what the query string asks
     * for, and takes its columns off the table while it is on: it is how a
     * link's own tally is counted, where the question is what clicking it would
     * show rather than what is showing now.
     */
    public static function fromQuery(Query $query, Blueprint $blueprint, ?Link $pinned = null): self
    {
        $view = $pinned ?? $blueprint->link($query->string('view'));
        $sortable = array_map(static fn (Field $field): string => $field->name(), $blueprint->sortable());
        $requested = $query->string('sort');
        $sort = in_array($requested, $sortable, true) ? $requested : $blueprint->defaultSort;

        $filters = [];

        foreach ($blueprint->filterable() as $field) {
            if ($pinned !== null && array_key_exists($field->name(), $pinned->filters())) {
                continue;
            }

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
            view: $view,
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
            view: $this->view,
        );
    }

    /**
     * The same list, read in pages of another size. What an extraction walks
     * with: the view stays exactly what the visitor filtered and sorted, and
     * only the size of the bite changes.
     */
    public function inPagesOf(int $perPage): self
    {
        return new self(
            page: $this->page,
            perPage: $perPage,
            sort: $this->sort,
            direction: $this->direction,
            filters: $this->filters,
            search: $this->search,
            view: $this->view,
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
     * risk, since the pattern is always bound as a parameter, but they are a
     * cost one: a bare "%" matches every row, which turns a search box into a
     * way to ask for a full scan on demand. Escaping is done here rather than in
     * each source so that no source can forget, and so the SQL stays one shape.
     *
     * The LIKE that consumes this has to name the escape character, which is
     * what like() is for: no dialect assumes SEARCH_ESCAPE on its own, so
     * without the clause an escaped term matches nothing.
     */
    public function searchPattern(): ?string
    {
        if ($this->search === null) {
            return null;
        }

        $escape = self::SEARCH_ESCAPE;
        $pattern = str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $this->search,
        );

        return '%' . $pattern . '%';
    }

    /** The condition a source binds searchPattern() to, escape character named. */
    public static function like(string $column): string
    {
        return "{$column} LIKE ? ESCAPE '" . self::SEARCH_ESCAPE . "'";
    }

    /** @return array<string, string> */
    public function toQuery(): array
    {
        $params = [
            'q' => $this->search,
            'sort' => $this->sort,
            'dir' => $this->sort === null ? null : $this->direction,
            'view' => $this->view?->key(),
            'page' => $this->page > 1 ? (string) $this->page : null,
        ];

        // What the link already pins is left out: it is spelled once, as the
        // link, and a URL repeating it would survive a change to the link's
        // definition as a filter nobody asked for.
        $filters = $this->view === null
            ? $this->filters
            : array_diff_assoc($this->filters, $this->view->filters());

        return array_filter([...$params, ...$filters], static fn (?string $value): bool => $value !== null);
    }
}
