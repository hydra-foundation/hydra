<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Paging;
use Hydra\Http\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which slice of a list a request asked for. Every value is clamped rather
 * than refused, and the page cap is the guard that keeps a typed URL from
 * becoming a full table scan, so each bound is pinned at both edges.
 */
#[CoversClass(Paging::class)]
final class PagingTest extends TestCase
{
    public function test_the_defaults_are_the_first_page_of_twenty_five(): void
    {
        $paging = new Paging;

        $this->assertSame(1, $paging->page);
        $this->assertSame(25, $paging->perPage);
    }

    #[DataProvider('pages')]
    public function test_the_page_is_clamped_to_the_first_and_the_cap(int $asked, int $expected): void
    {
        $this->assertSame($expected, (new Paging(page: $asked))->page);
    }

    /** @return iterable<string, array{int, int}> */
    public static function pages(): iterable
    {
        yield 'negative' => [-3, 1];
        yield 'zero' => [0, 1];
        yield 'the first' => [1, 1];
        yield 'an ordinary one' => [7, 7];
        yield 'the cap' => [Paging::MAX_PAGE, Paging::MAX_PAGE];
        yield 'one past the cap' => [Paging::MAX_PAGE + 1, Paging::MAX_PAGE];
    }

    #[DataProvider('sizes')]
    public function test_per_page_is_at_least_one(int $asked, int $expected): void
    {
        $this->assertSame($expected, (new Paging(perPage: $asked))->perPage);
    }

    /** @return iterable<string, array{int, int}> */
    public static function sizes(): iterable
    {
        yield 'negative' => [-5, 1];
        yield 'zero' => [0, 1];
        yield 'one' => [1, 1];
        yield 'two' => [2, 2];
    }

    #[DataProvider('offsets')]
    public function test_the_offset_is_the_rows_before_the_page(int $page, int $perPage, int $expected): void
    {
        $this->assertSame($expected, (new Paging($page, $perPage))->offset());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function offsets(): iterable
    {
        yield 'the first page' => [1, 25, 0];
        yield 'the second' => [2, 25, 25];
        yield 'one per page' => [4, 1, 3];
    }

    public function test_on_page_keeps_the_size_and_moves_the_page(): void
    {
        $paging = (new Paging(3, 10))->onPage(5);

        $this->assertSame(5, $paging->page);
        $this->assertSame(10, $paging->perPage);
    }

    public function test_on_page_is_clamped_like_the_constructor(): void
    {
        $this->assertSame(Paging::MAX_PAGE, (new Paging)->onPage(Paging::MAX_PAGE + 1)->page);
        $this->assertSame(1, (new Paging(4))->onPage(0)->page);
    }

    public function test_in_pages_of_keeps_the_page_and_changes_the_size(): void
    {
        $paging = (new Paging(3, 10))->inPagesOf(500);

        $this->assertSame(3, $paging->page);
        $this->assertSame(500, $paging->perPage);
    }

    public function test_in_pages_of_is_clamped_like_the_constructor(): void
    {
        $this->assertSame(1, (new Paging)->inPagesOf(0)->perPage);
    }

    public function test_from_query_reads_page_and_per_page(): void
    {
        $paging = Paging::fromQuery(Query::fromUrl('/posts?page=3&per_page=10'), 20, 100);

        $this->assertSame(3, $paging->page);
        $this->assertSame(10, $paging->perPage);
    }

    public function test_from_query_with_nothing_asked_is_the_first_page_at_the_default_size(): void
    {
        $paging = Paging::fromQuery(Query::fromUrl('/posts'), 20, 100);

        $this->assertSame(1, $paging->page);
        $this->assertSame(20, $paging->perPage);
    }

    #[DataProvider('askedSizes')]
    public function test_from_query_clamps_per_page_to_the_ceiling(string $asked, int $expected): void
    {
        $paging = Paging::fromQuery(Query::fromUrl('/posts?' . $asked), 20, 100);

        $this->assertSame($expected, $paging->perPage);
    }

    /** @return iterable<string, array{string, int}> */
    public static function askedSizes(): iterable
    {
        yield 'the ceiling' => ['per_page=100', 100];
        yield 'one over the ceiling' => ['per_page=101', 100];
        yield 'far over it' => ['per_page=5000', 100];
        yield 'zero' => ['per_page=0', 1];
        yield 'negative' => ['per_page=-4', 1];
        // Not an integer at all: the caller's default, as if nothing was asked.
        yield 'a word' => ['per_page=abc', 20];
        yield 'empty' => ['per_page=', 20];
        yield 'an array' => ['per_page[]=5', 20];
    }

    #[DataProvider('askedPages')]
    public function test_from_query_clamps_the_page(string $asked, int $expected): void
    {
        $paging = Paging::fromQuery(Query::fromUrl('/posts?' . $asked), 20, 100);

        $this->assertSame($expected, $paging->page);
    }

    /** @return iterable<string, array{string, int}> */
    public static function askedPages(): iterable
    {
        yield 'past the cap' => ['page=99999', Paging::MAX_PAGE];
        yield 'zero' => ['page=0', 1];
        yield 'negative' => ['page=-2', 1];
        yield 'a word' => ['page=abc', 1];
        yield 'an array' => ['page[]=3', 1];
    }

    public function test_a_default_above_the_ceiling_is_clamped_too(): void
    {
        // The caller's own default is not trusted past the ceiling it set:
        // the two are usually written apart, and only one of them gets edited.
        $paging = Paging::fromQuery(Query::fromUrl('/posts'), 500, 100);

        $this->assertSame(100, $paging->perPage);
    }

    public function test_a_ceiling_below_one_still_pages_by_one(): void
    {
        $paging = Paging::fromQuery(Query::fromUrl('/posts?per_page=10'), 20, 0);

        $this->assertSame(1, $paging->perPage);
    }
}
