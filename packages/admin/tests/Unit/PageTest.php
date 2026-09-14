<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pagination arithmetic, which is all a page is. Every method here is a
 * one-liner and every one of them is an off-by-one waiting to be read by a
 * human as "Showing 1 to 0 of 0".
 */
#[CoversClass(Page::class)]
final class PageTest extends TestCase
{
    #[DataProvider('counts')]
    public function test_it_divides_the_total_into_pages(int $total, int $perPage, int $expected): void
    {
        $this->assertSame($expected, $this->page(total: $total, perPage: $perPage)->pages());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function counts(): iterable
    {
        yield 'an exact fit' => [50, 25, 2];
        yield 'a partial last page' => [51, 25, 3];
        yield 'fewer rows than a page' => [3, 25, 1];
        // One page, not zero: a reader is always on a page, even an empty one.
        yield 'nothing at all' => [0, 25, 1];
        yield 'one per page' => [7, 1, 7];
    }

    public function test_an_empty_page_counts_from_zero(): void
    {
        // Rather than from the offset plus one, which would read as "Showing
        // 26 to 25 of 0" on a list somebody filtered down to nothing.
        $page = $this->page(rows: [], total: 0, pageNumber: 2);

        $this->assertSame(0, $page->from());
        $this->assertTrue($page->isEmpty());
    }

    public function test_a_populated_page_reports_the_span_it_holds(): void
    {
        $page = $this->page(rows: $this->rows(10), total: 100, pageNumber: 3, perPage: 10);

        $this->assertSame(21, $page->from());
        $this->assertSame(30, $page->to());
        $this->assertFalse($page->isEmpty());
    }

    public function test_a_short_last_page_reports_only_what_it_holds(): void
    {
        $page = $this->page(rows: $this->rows(4), total: 24, pageNumber: 3, perPage: 10);

        $this->assertSame(21, $page->from());
        $this->assertSame(24, $page->to());
    }

    public function test_the_first_page_has_nothing_before_it(): void
    {
        $page = $this->page(rows: $this->rows(10), total: 100, pageNumber: 1, perPage: 10);

        $this->assertFalse($page->hasPrevious());
        $this->assertTrue($page->hasNext());
    }

    public function test_the_last_page_has_nothing_after_it(): void
    {
        $page = $this->page(rows: $this->rows(10), total: 100, pageNumber: 10, perPage: 10);

        $this->assertTrue($page->hasPrevious());
        $this->assertFalse($page->hasNext());
    }

    public function test_a_single_page_has_neighbours_on_neither_side(): void
    {
        $page = $this->page(rows: $this->rows(3), total: 3, pageNumber: 1, perPage: 25);

        $this->assertFalse($page->hasPrevious());
        $this->assertFalse($page->hasNext());
    }

    /** @param list<array<string, mixed>> $rows */
    private function page(
        array $rows = [],
        int $total = 0,
        int $pageNumber = 1,
        int $perPage = 25,
    ): Page {
        return new Page($rows, $total, new Criteria(page: $pageNumber, perPage: $perPage));
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $count): array
    {
        return array_map(static fn (int $i): array => ['id' => $i], range(1, $count));
    }
}
