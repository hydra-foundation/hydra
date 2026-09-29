<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Http\Paginated;

/**
 * One slice of a source's rows, plus the criteria that produced it. The
 * arithmetic is {@see Paginated}'s; what a page adds is the admin's half of
 * the criteria and the note.
 */
final readonly class Page
{
    /** @var Paginated<array<string, mixed>> */
    private Paginated $paginated;

    /**
     * @param list<array<string, mixed>> $rows
     * @param string|null $note what the source wants said about these rows, shown beside the count
     */
    public function __construct(
        public array $rows,
        public int $total,
        public Criteria $criteria,
        public ?string $note = null,
    ) {
        $this->paginated = new Paginated($rows, $total, $criteria->paging);
    }

    public function pages(): int
    {
        return $this->paginated->pages();
    }

    public function from(): int
    {
        return $this->paginated->from();
    }

    public function to(): int
    {
        return $this->paginated->to();
    }

    public function hasPrevious(): bool
    {
        return $this->paginated->hasPrevious();
    }

    public function hasNext(): bool
    {
        return $this->paginated->hasNext();
    }

    public function isEmpty(): bool
    {
        return $this->paginated->isEmpty();
    }
}
