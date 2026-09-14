<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\Extractor;
use Hydra\Admin\Tests\Support\RecordingSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading a whole list out of a source that only answers in pages: where it
 * starts, when it stops, and what it keeps of the view it was given.
 */
#[CoversClass(Extractor::class)]
final class ExtractorTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function rows(int $count): array
    {
        return array_map(static fn (int $n): array => ['id' => $n], range(1, $count));
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @return list<mixed>
     */
    private static function ids(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

    public function test_it_reads_past_the_page_a_list_would_have_shown(): void
    {
        $source = new RecordingSource(self::rows(7));
        $extractor = new Extractor($source, chunk: 3);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], self::ids($extractor->rows(new Criteria(perPage: 2))));
        $this->assertSame([3, 3, 3], array_map(
            static fn (Criteria $criteria): int => $criteria->perPage,
            $source->asked,
        ));
    }

    /**
     * The last page is the one that comes back short. The reported total is
     * never consulted, because counting is the expensive half of a paginated
     * query and a source is entitled to skip it.
     */
    public function test_a_source_that_does_not_count_still_extracts_in_full(): void
    {
        $extractor = new Extractor(new RecordingSource(self::rows(5), total: 0), chunk: 2);

        $this->assertSame([1, 2, 3, 4, 5], self::ids($extractor->rows(new Criteria)));
    }

    public function test_a_page_that_divides_evenly_ends_on_an_empty_read(): void
    {
        // Four rows in chunks of two: nothing says the second page was the last
        // until the third comes back with nothing in it.
        $source = new RecordingSource(self::rows(4));
        $extractor = new Extractor($source, chunk: 2);

        $this->assertSame([1, 2, 3, 4], self::ids($extractor->rows(new Criteria)));
        $this->assertCount(3, $source->asked);
    }

    public function test_it_stops_at_the_limit_it_was_given(): void
    {
        $source = new RecordingSource(self::rows(1000));

        $this->assertSame([1, 2, 3], self::ids((new Extractor($source))->rows(new Criteria, limit: 3)));

        // And it did not read a chunk it was never going to yield.
        $this->assertSame(3, $source->asked[0]->perPage);
    }

    /**
     * A view of a list is its filters, its search and its order. Where in it the
     * visitor had got to is not part of that, and an export that started from
     * their page would quietly leave the earlier rows out of the file.
     */
    public function test_it_extracts_the_view_rather_than_the_place_in_it(): void
    {
        $source = new RecordingSource(self::rows(3));

        iterator_to_array((new Extractor($source))->rows(new Criteria(
            page: 4,
            perPage: 2,
            sort: 'username',
            direction: 'desc',
            filters: ['status' => 'active'],
            search: 'ada',
        )));

        $asked = $source->asked[0];

        $this->assertSame(1, $asked->page);
        $this->assertSame('username', $asked->sort);
        $this->assertSame('desc', $asked->direction);
        $this->assertSame(['status' => 'active'], $asked->filters);
        $this->assertSame('ada', $asked->search);
    }

    public function test_an_empty_source_yields_nothing_and_is_read_once(): void
    {
        $source = new RecordingSource;

        $this->assertSame([], self::ids((new Extractor($source))->rows(new Criteria)));
        $this->assertCount(1, $source->asked);
    }

    public function test_a_limit_below_one_is_still_a_row(): void
    {
        // max(1) rather than an empty file: zero is not a view anybody asked for,
        // and a download of nothing at all reads as a broken export.
        $this->assertSame([1], self::ids((new Extractor(new RecordingSource(self::rows(3))))->rows(new Criteria, limit: 0)));
    }
}
