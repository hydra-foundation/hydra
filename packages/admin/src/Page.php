<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Page
 *
 * One slice of a source's rows, plus the criteria that produced it.
 */
final readonly class Page
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public array $rows,
        public int $total,
        public Criteria $criteria,
    ) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->criteria->perPage)));
    }

    public function from(): int
    {
        return $this->rows === [] ? 0 : $this->criteria->offset() + 1;
    }

    public function to(): int
    {
        return $this->criteria->offset() + count($this->rows);
    }

    public function hasPrevious(): bool
    {
        return $this->criteria->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->criteria->page < $this->pages();
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
