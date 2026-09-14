<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Generator;
use Hydra\Admin\Contracts\SourceInterface;

/**
 * Reads out everything a list's criteria match, not just the page the visitor
 * is standing on. A list screen asks its source for one page; an export, a
 * report or a job wants the whole view, and this is the one thing that knows
 * how to walk a source that only answers in pages.
 *
 * It yields rather than collects, so what a caller does with a row is over
 * before the next one is read, and the source's own page size is the only slice
 * ever held. The rows are the source's own: whatever {@see Field} would do to
 * them is the caller's business, which is what lets a CSV, a JSON dump and a
 * mail merge share this.
 */
final class Extractor
{
    /**
     * Rows per read. Large enough that a long extraction is few round trips,
     * small enough that one of them is still a cheap query.
     */
    public const CHUNK = 500;

    /**
     * The most rows one extraction yields unless a caller says otherwise. A cap
     * and not a total: this is the same blast-radius reasoning as
     * {@see Criteria::MAX_PAGE}, since an export is one request that can ask
     * the database for a whole table.
     */
    public const MAX_ROWS = 50_000;

    public function __construct(
        private readonly SourceInterface $source,
        private readonly int $chunk = self::CHUNK,
    ) {}

    /**
     * Every row the criteria match, from the first, up to $limit.
     *
     * The criteria's own page is ignored: a view of a list is its filters, its
     * search and its order, and an extraction is of the view rather than of the
     * place in it the visitor happened to have reached.
     *
     * A page is the last one when the source returns fewer rows than were asked
     * for. The reported total is not consulted: a source is free not to count
     * (counting is the expensive half of a paginated query) and one that does
     * not must still extract correctly.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(Criteria $criteria, ?int $limit = null): Generator
    {
        $limit = max(1, $limit ?? self::MAX_ROWS);
        $size = max(1, min($this->chunk, $limit));
        $criteria = $criteria->inPagesOf($size);
        $taken = 0;

        for ($page = 1; ; $page++) {
            $rows = $this->source->page($criteria->onPage($page))->rows;

            foreach ($rows as $row) {
                yield $row;

                if (++$taken >= $limit) {
                    return;
                }
            }

            if (count($rows) < $size) {
                return;
            }
        }
    }
}
