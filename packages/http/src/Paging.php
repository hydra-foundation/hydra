<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * Which slice of a list a request asked for: a page number and a page size.
 *
 * Normalised, never refused. A page or size out of range is clamped rather
 * than answered with an error, so a URL somebody typed or edited always reads
 * as some page. {@see Paginated} is the answer to one of these.
 */
final readonly class Paging
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

    public int $page;
    public int $perPage;

    public function __construct(int $page = 1, int $perPage = 25)
    {
        $this->page = min(max(1, $page), self::MAX_PAGE);
        $this->perPage = max(1, $perPage);
    }

    /**
     * `page` and `per_page` off the query string. A missing or non-integer
     * per_page falls back to $perPage, and whatever results is clamped to
     * 1..$maxPerPage, the default included.
     *
     * The ceiling has no default on purpose: how large a bite is still cheap
     * depends on the list, and only the caller knows it.
     */
    public static function fromQuery(Query $query, int $perPage, int $maxPerPage): self
    {
        return new self(
            page: $query->int('page', 1),
            perPage: min($query->int('per_page', $perPage), $maxPerPage),
        );
    }

    /** The rows before this page, which is what an OFFSET skips. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** The same size of page, at another page. */
    public function onPage(int $page): self
    {
        return new self($page, $this->perPage);
    }

    /** The same page number, in pages of another size. */
    public function inPagesOf(int $perPage): self
    {
        return new self($this->page, $perPage);
    }
}
