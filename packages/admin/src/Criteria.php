<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Http\Query;

/**
 * Criteria
 *
 * A list screen's request state: which page, which order, which filters. The
 * constructor normalises what a source interpolates rather than binds; only
 * fromQuery() can whitelist sort and filter keys against the blueprint.
 */
final readonly class Criteria
{
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
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
        $this->sort = $sort;
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->filters = $filters;
        $this->search = $search;
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
