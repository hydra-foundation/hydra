<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeZone;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Criteria;
use Hydra\Admin\DateRange;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\FieldType;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Http\Query;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A filterable date or datetime is narrowed by a From and a To day rather than
 * matched exactly, and the range has to survive every URL the list draws: the
 * pager, a sort header, a filter link, the export.
 */
#[CoversClass(Criteria::class)]
final class CriteriaRangeTest extends TestCase
{
    private const ZONE = 'America/Edmonton';

    public function test_a_filterable_datetime_is_read_as_a_range_in_the_readers_zone(): void
    {
        $criteria = $this->criteria(['created_at_from' => '2026-10-01', 'created_at_to' => '2026-10-05']);

        $range = $criteria->ranges['created_at'] ?? null;
        $this->assertInstanceOf(DateRange::class, $range);
        $this->assertSame(FieldType::DateTime, $range->type);
        $this->assertSame(self::ZONE, $range->from?->getTimezone()->getName());
        $this->assertSame('2026-10-05', $range->toValue());
    }

    public function test_a_filterable_date_is_a_range_too(): void
    {
        $range = $this->criteria(['due_on_to' => '2026-10-05'])->ranges['due_on'] ?? null;

        $this->assertSame(FieldType::Date, $range?->type);
        $this->assertNull($range->from);
    }

    public function test_a_range_is_never_an_exact_match(): void
    {
        $criteria = $this->criteria(['created_at' => '2026-10-01 12:00:00', 'created_at_from' => '2026-10-01']);

        $this->assertArrayNotHasKey('created_at', $criteria->filters);
        $this->assertArrayHasKey('created_at', $criteria->ranges);
    }

    public function test_equality_filters_are_read_as_before(): void
    {
        $criteria = $this->criteria(['role' => 'admin', 'created_at_from' => '2026-10-01']);

        $this->assertSame(['role' => 'admin'], $criteria->filters);
    }

    public function test_days_that_are_not_days_are_no_range(): void
    {
        $this->assertSame([], $this->criteria(['created_at_from' => 'soon', 'created_at_to' => ''])->ranges);
    }

    public function test_a_field_that_is_not_filterable_has_no_range(): void
    {
        $this->assertSame([], $this->criteria(['updated_at_from' => '2026-10-01'])->ranges);
    }

    public function test_without_a_zone_the_days_are_utcs(): void
    {
        $criteria = Criteria::fromQuery($this->query(['created_at_from' => '2026-10-01']), $this->blueprint());

        $this->assertSame('UTC', $criteria->ranges['created_at']->from?->getTimezone()->getName());
    }

    public function test_the_range_is_written_back_into_the_query_string(): void
    {
        $criteria = $this->criteria(['created_at_from' => '2026-10-05', 'created_at_to' => '2026-10-01', 'role' => 'admin']);

        $this->assertSame(
            ['sort' => 'id', 'dir' => 'desc', 'role' => 'admin', 'created_at_from' => '2026-10-01', 'created_at_to' => '2026-10-05'],
            $criteria->toQuery(),
        );
    }

    public function test_an_open_end_is_left_out_of_the_query_string(): void
    {
        $query = $this->criteria(['created_at_to' => '2026-10-05'])->toQuery();

        $this->assertSame('2026-10-05', $query['created_at_to'] ?? null);
        $this->assertArrayNotHasKey('created_at_from', $query);
    }

    public function test_a_narrowed_list_is_not_the_bare_url(): void
    {
        $this->assertSame(
            '?sort=id&dir=desc&created_at_from=2026-10-01',
            $this->criteria(['created_at_from' => '2026-10-01'])->queryString($this->blueprint()),
        );
    }

    public function test_another_page_or_page_size_keeps_the_range(): void
    {
        $criteria = $this->criteria(['created_at_from' => '2026-10-01']);

        $this->assertSame($criteria->ranges, $criteria->onPage(3)->ranges);
        $this->assertSame($criteria->ranges, $criteria->inPagesOf(500)->ranges);
    }

    public function test_a_criteria_made_by_hand_has_no_range_unless_given_one(): void
    {
        $range = DateRange::fromDays('2026-10-01', null, FieldType::DateTime, new DateTimeZone('UTC'));
        $this->assertNotNull($range);

        $this->assertSame([], (new Criteria)->ranges);
        $this->assertSame(['created_at' => $range], (new Criteria(ranges: ['created_at' => $range]))->ranges);
    }

    /** @param array<string, string> $query */
    private function criteria(array $query): Criteria
    {
        return Criteria::fromQuery($this->query($query), $this->blueprint(), zone: new DateTimeZone(self::ZONE));
    }

    /** @param array<string, string> $query */
    private function query(array $query): Query
    {
        return Query::fromRequest((new ServerRequest('GET', '/admin/tickets'))->withQueryParams($query));
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('tickets')
            ->source(new ArraySource)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id(),
                Field::select('role', ['user' => 'User', 'admin' => 'Admin'])->filterable(),
                Field::datetime('created_at')->filterable(),
                Field::date('due_on')->filterable(),
                Field::datetime('updated_at'),
            )
            ->compile();
    }
}
