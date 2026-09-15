<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every list source owes the admin, published because this is the
 * seam an application fills most often: the framework has no ORM, so a module
 * hands over one of these and the admin never reads a table of its own accord.
 * Every module written against the checklist adds another implementation, and
 * until now each was written with nothing to check it against.
 *
 * What the admin assumes, and cannot verify at the call site, is that paging is
 * a partition: each page holds at most what was asked for, a page short of that
 * is the last one, and walking from the first covers every matching row exactly
 * once. {@see \Hydra\Admin\Extractor} rests the whole of an export on the middle
 * clause, and a source that returns a short page early truncates a download
 * without failing.
 *
 * The other assumption is that a search term is data. It arrives from a query
 * string, and a source that interpolates it into a LIKE rather than binding
 * {@see Criteria::searchPattern()} turns the list's search box into a way to ask
 * for every row in the table.
 */
abstract class SourceContractTestCase extends TestCase
{
    /**
     * The source under test, holding {@see rowCount()} rows.
     *
     * The same instance for the whole of one test: the write cases read back
     * what they just wrote, so a hook that builds a fresh source per call
     * cannot fulfil them.
     */
    abstract protected function source(): SourceInterface;

    /**
     * How many rows the source holds when nothing is filtered or searched.
     *
     * At least three, or paging is a single page and the partition this case
     * exists to check is never exercised.
     */
    abstract protected function rowCount(): int;

    /**
     * A column the source can order by, whose values are distinct across those
     * rows. Ties have no defined order, so a column with them cannot say
     * whether descending really reversed anything.
     */
    abstract protected function sortColumn(): string;

    /**
     * A term that matches some but not all rows, or null when the source has
     * nothing searchable. Null is a real answer rather than an opt-out: a
     * source that ignores search is held to ignoring it consistently.
     *
     * A fixture that answers with a term also promises that no row's searchable
     * text contains "%", "_" or {@see Criteria::SEARCH_ESCAPE}, since the
     * wildcard cases below read a match for one of those as the term having
     * reached the pattern unescaped.
     */
    protected function searchMatchingSomeRows(): ?string
    {
        return null;
    }

    /**
     * Whether the source counts what it matches. Counting is the expensive half
     * of a paginated query and {@see \Hydra\Admin\Extractor} deliberately does
     * not rely on it, but {@see Page::pages()} and every pagination control do,
     * so a source that reports a total is held to the right one.
     */
    protected function counts(): bool
    {
        return true;
    }

    /**
     * The id of a listed row, for sources whose key is not "id".
     *
     * @param array<string, mixed> $row
     */
    protected function idOf(array $row): string
    {
        return (string) $row['id'];
    }

    final protected function criteria(int $page = 1, int $perPage = 25, string $direction = 'asc', ?string $search = null): Criteria
    {
        return new Criteria(
            page: $page,
            perPage: $perPage,
            sort: $this->sortColumn(),
            direction: $direction,
            search: $search,
        );
    }

    /**
     * Every row the source yields when walked from the first page, in order.
     *
     * Deliberately the walk {@see \Hydra\Admin\Extractor::rows()} performs and
     * not a single large page: a source can answer one page of everything
     * correctly and still have paging wrong, and that is the failure an export
     * finds in production rather than here.
     *
     * @return list<array<string, mixed>>
     */
    final protected function walk(int $perPage = 2, string $direction = 'asc', ?string $search = null): array
    {
        $rows = [];

        // Bounded rather than a while(true): a source that ignores the page
        // number returns the same full page for ever, and the assertion below
        // says that plainly instead of the suite hanging.
        for ($page = 1; $page <= $this->rowCount() + 2; $page++) {
            $slice = $this->source()->page($this->criteria($page, $perPage, $direction, $search))->rows;
            $rows = [...$rows, ...$slice];

            if (count($slice) < $perPage) {
                return $rows;
            }
        }

        $this->fail(sprintf(
            'Walking the source never reached a short page: after %d pages of %d it is still returning full ones, '
            . 'so it is ignoring the page number and an export would never terminate.',
            $this->rowCount() + 2,
            $perPage,
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    final protected function idsOf(array $rows): array
    {
        return array_map($this->idOf(...), $rows);
    }

    public function test_the_fixture_holds_enough_rows_to_page_through(): void
    {
        // Not a test of the source: a case that silently degrades to one page
        // reports everything below as passing while checking none of it.
        $this->assertGreaterThanOrEqual(
            3,
            $this->rowCount(),
            'A source contract case needs at least three rows, or paging is never exercised.',
        );
    }

    public function test_a_page_holds_no_more_rows_than_were_asked_for(): void
    {
        $page = $this->source()->page($this->criteria(perPage: 2));

        $this->assertLessThanOrEqual(
            2,
            count($page->rows),
            'The source returned more rows than perPage, so the list renders a page the pagination does not describe.',
        );
    }

    public function test_paging_reaches_every_row_exactly_once(): void
    {
        $ids = $this->idsOf($this->walk());

        $this->assertCount(
            $this->rowCount(),
            $ids,
            'Paging from the first page did not yield every row: an export of this list would be short.',
        );
        $this->assertSame(
            array_values(array_unique($ids)),
            $ids,
            'Paging yielded the same row twice, so the page number is not reaching the offset.',
        );
    }

    public function test_a_page_before_the_last_one_is_full(): void
    {
        // What tells Extractor it has reached the end. A source that returns a
        // short page early — a filter applied after the slice is the usual way
        // — stops an export there, with no error and no missing-rows signal.
        $page = $this->source()->page($this->criteria(page: 1, perPage: 2));

        $this->assertCount(
            2,
            $page->rows,
            'The first of several pages came back short, which is how a source tells an export it has finished.',
        );
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        // Criteria clamps the page number to MAX_PAGE and no further, so any
        // page up to it can arrive from a URL somebody typed.
        $page = $this->source()->page($this->criteria(page: $this->rowCount() + 5, perPage: 2));

        $this->assertSame([], $page->rows);
    }

    public function test_the_page_carries_back_the_criteria_it_was_given(): void
    {
        // Every pagination link and sort header is built from Page::$criteria
        // rather than from the request, so a source that substitutes its own
        // sends the reader to a view they did not ask for.
        $criteria = $this->criteria(page: 2, perPage: 2);
        $page = $this->source()->page($criteria);

        // Equality rather than identity: rebuilding an equal Criteria is fine,
        // and quietly substituting a different page or order is the defect.
        $this->assertEquals($criteria, $page->criteria);
    }

    public function test_descending_is_ascending_reversed(): void
    {
        $ascending = $this->idsOf($this->walk(direction: 'asc'));
        $descending = $this->idsOf($this->walk(direction: 'desc'));

        $this->assertSame(array_reverse($ascending), $descending);
    }

    public function test_the_total_counts_every_matching_row_and_not_the_page(): void
    {
        if (!$this->counts()) {
            $this->assertSame(0, $this->source()->page($this->criteria(perPage: 2))->total);

            return;
        }

        $page = $this->source()->page($this->criteria(perPage: 2));

        $this->assertSame(
            $this->rowCount(),
            $page->total,
            'The total is not the size of the match, so Page::pages() is wrong and the list hides rows behind a '
            . 'pagination control that says there are none.',
        );
    }

    public function test_a_search_narrows_what_comes_back(): void
    {
        $term = $this->searchMatchingSomeRows();

        if ($term === null) {
            // A source with nothing searchable still has to answer a search,
            // because the query string can always carry one.
            $this->assertCount(
                $this->rowCount(),
                $this->walk(search: 'anything-at-all'),
                'The source has no searchable column but did not ignore the search term.',
            );

            return;
        }

        $matched = $this->walk(search: $term);

        $this->assertNotSame([], $matched, 'The term said to match some rows matched none.');
        $this->assertLessThan(
            $this->rowCount(),
            count($matched),
            'The term said to match some rows matched all of them, so nothing here can tell narrowing from ignoring.',
        );
    }

    public function test_a_search_that_matches_nothing_is_an_empty_page_rather_than_an_error(): void
    {
        if ($this->searchMatchingSomeRows() === null) {
            $this->assertCount($this->rowCount(), $this->walk(search: 'no-row-holds-this'));

            return;
        }

        $this->assertSame([], $this->walk(search: 'zzz-no-row-holds-this-zzz'));
    }

    /** @return array<string, array{string}> */
    public static function termsThatCannotMatchEverything(): array
    {
        return [
            'the LIKE wildcard' => ['%'],
            'the single character wildcard' => ['_'],
            'the escape character itself' => [Criteria::SEARCH_ESCAPE],
        ];
    }

    #[DataProvider('termsThatCannotMatchEverything')]
    public function test_a_search_wildcard_cannot_widen_the_match(string $term): void
    {
        if ($this->searchMatchingSomeRows() === null) {
            $this->expectNotToPerformAssertions();

            return;
        }

        // Criteria::searchPattern() escapes these, so a source that binds it is
        // already right and a source that builds its own LIKE from
        // Criteria::$search is not. The consequence is a search box that
        // returns the whole table for one keystroke.
        $this->assertLessThan(
            $this->rowCount(),
            count($this->walk(search: $term)),
            sprintf('Searching for %s matched every row, so the term is reaching the pattern unescaped.', $term),
        );
    }
}
