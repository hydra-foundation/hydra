<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * One page of a list: its items, how many there are in all, and the
 * {@see Paging} that asked for them. What {@see Responder::paginated()}
 * renders, and what the admin's Page is built on.
 *
 * @template T
 */
final readonly class Paginated
{
    /** @var list<T> */
    public array $items;

    public int $total;

    /**
     * @param array<T> $items in order; any keys are dropped, so a list that
     *                        was filtered still encodes as a JSON array
     */
    public function __construct(array $items, int $total, public Paging $paging)
    {
        $this->items = array_values($items);
        $this->total = max(0, $total);
    }

    /** At least one: a reader is always on a page, even an empty one. */
    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->paging->perPage));
    }

    /** The position of the first item, counting from one, or 0 when empty. */
    public function from(): int
    {
        return $this->items === [] ? 0 : $this->paging->offset() + 1;
    }

    /** The position of the last item. */
    public function to(): int
    {
        return $this->paging->offset() + count($this->items);
    }

    public function hasPrevious(): bool
    {
        return $this->paging->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->paging->page < $this->pages();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The same page with each item passed through $map: a row in, the shape
     * the API promises out.
     *
     * @template U
     * @param callable(T): U $map
     * @return self<U>
     */
    public function map(callable $map): self
    {
        return new self(array_map($map, $this->items), $this->total, $this->paging);
    }
}
