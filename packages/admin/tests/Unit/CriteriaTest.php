<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Criteria;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Http\Query;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase
{
    public function test_it_ignores_a_sort_column_the_module_never_declared(): void
    {
        $criteria = $this->criteria(['sort' => 'password_hash', 'dir' => 'desc']);

        $this->assertSame('id', $criteria->sort);
    }

    public function test_it_accepts_a_declared_sort_column(): void
    {
        $criteria = $this->criteria(['sort' => 'username', 'dir' => 'desc']);

        $this->assertSame('username', $criteria->sort);
        $this->assertSame('desc', $criteria->direction);
    }

    public function test_an_explicit_direction_overrides_the_module_default(): void
    {
        $this->assertSame('desc', $this->criteria([])->direction);
        $this->assertSame('asc', $this->criteria(['dir' => 'asc'])->direction);
        $this->assertSame('desc', $this->criteria(['dir' => 'sideways'])->direction);
    }

    public function test_it_ignores_a_filter_value_outside_the_declared_options(): void
    {
        $this->assertSame([], $this->criteria(['role' => 'superuser'])->filters);
        $this->assertSame(['role' => 'admin'], $this->criteria(['role' => 'admin'])->filters);
    }

    public function test_it_clamps_the_page_to_the_first(): void
    {
        $this->assertSame(1, $this->criteria(['page' => '-3'])->page);
        $this->assertSame(1, $this->criteria(['page' => 'nonsense'])->page);
        $this->assertSame(4, $this->criteria(['page' => '4'])->page);
    }

    public function test_a_blank_search_reads_as_no_search(): void
    {
        $this->assertNull($this->criteria(['q' => '   '])->search);
        $this->assertSame('ada', $this->criteria(['q' => ' ada '])->search);
    }

    public function test_it_round_trips_through_the_query_string(): void
    {
        $criteria = $this->criteria(['q' => 'ada', 'sort' => 'username', 'dir' => 'desc', 'role' => 'admin', 'page' => '2']);

        $this->assertSame(
            ['q' => 'ada', 'sort' => 'username', 'dir' => 'desc', 'page' => '2', 'role' => 'admin'],
            $criteria->toQuery(),
        );
    }

    public function test_the_offset_follows_the_page_size(): void
    {
        $this->assertSame(50, $this->criteria(['page' => '3'])->offset());
    }

    public function test_direct_construction_normalises_what_a_source_interpolates(): void
    {
        $criteria = new Criteria(page: -3, perPage: 0, direction: 'asc; DROP TABLE users');

        $this->assertSame(1, $criteria->page);
        $this->assertSame(1, $criteria->perPage);
        $this->assertSame('asc', $criteria->direction);
        $this->assertSame(0, $criteria->offset());
    }

    public function test_direct_construction_keeps_a_legitimate_descending_order(): void
    {
        $this->assertSame('desc', (new Criteria(direction: 'DESC'))->direction);
    }

    /** @param array<string, string> $query */
    private function criteria(array $query): Criteria
    {
        return Criteria::fromQuery(
            Query::fromRequest((new ServerRequest('GET', '/admin/users'))->withQueryParams($query)),
            $this->blueprint(),
        );
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id(),
                Field::text('username')->sortable()->searchable(),
                Field::select('role', ['user' => 'User', 'admin' => 'Admin'])->filterable(),
            )
            ->compile();
    }

    public function test_a_page_number_is_capped_so_an_offset_cannot_run_away(): void
    {
        // page becomes an OFFSET, and the database walks every row it skips —
        // so an unbounded page is a way to ask for a full scan from a query
        // string. The cap bounds that without needing to know the row count.
        $this->assertSame(Criteria::MAX_PAGE, $this->criteria(['page' => '999999999'])->page);
        $this->assertSame(1, $this->criteria(['page' => '-5'])->page, 'and it still floors at the first page');
    }

    public function test_a_search_term_is_capped_rather_than_refused(): void
    {
        $criteria = $this->criteria(['q' => str_repeat('a', Criteria::MAX_SEARCH + 50)]);

        $this->assertSame(Criteria::MAX_SEARCH, mb_strlen((string) $criteria->search));
    }

    public function test_a_search_pattern_wraps_the_term_in_wildcards(): void
    {
        $this->assertSame('%ada%', $this->criteria(['q' => 'ada'])->searchPattern());
    }

    public function test_nothing_searched_for_is_no_pattern_at_all(): void
    {
        $this->assertNull($this->criteria([])->searchPattern());
        $this->assertNull($this->criteria(['q' => '   '])->searchPattern());
    }

    public function test_the_terms_own_wildcards_are_escaped(): void
    {
        // A bare "%" would otherwise match every row: not an injection (the
        // pattern is bound), but a way to turn the search box into a request
        // for a full scan.
        $this->assertSame('%\\%%', $this->criteria(['q' => '%'])->searchPattern());
        $this->assertSame('%a\\_b%', $this->criteria(['q' => 'a_b'])->searchPattern());
        $this->assertSame('%\\\\%', $this->criteria(['q' => '\\'])->searchPattern());
    }
}
