<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\DateRange;
use Hydra\Admin\FieldType;
use Hydra\Admin\Testing\SourceContractTestCase;
use Hydra\Admin\Tests\Support\AdvertisedRangeSource;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * That the day-range half of the published case fails when it should: a
 * source that describes a timestamp as filterable and never narrows by it is
 * the same invisible defect as an equality filter left unapplied.
 */
#[CoversClass(SourceContractTestCase::class)]
final class SourceContractRangesTest extends TestCase
{
    public function test_a_source_that_ignores_the_range_it_advertises_fails_the_case(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource(applies: false), ['2026-10-02', '2026-10-03']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('returned every row');

        $case->test_each_day_range_narrows_what_comes_back();
    }

    public function test_an_advertised_range_with_no_days_to_run_it_with_is_reported(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource, null);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('filters by joined_at');

        $case->test_every_filter_the_source_advertises_has_a_value_to_check_it_with();
    }

    public function test_days_that_hold_no_row_are_reported_rather_than_read_as_narrowing(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource, ['2030-01-01', null]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('matched no row');

        $case->test_each_day_range_narrows_what_comes_back();
    }

    public function test_a_source_that_narrows_by_what_it_advertises_passes_both(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource, ['2026-10-02', '2026-10-03']);

        $case->test_every_filter_the_source_advertises_has_a_value_to_check_it_with();
        $case->test_each_day_range_narrows_what_comes_back();

        $this->assertSame(['2026-10-02', '2026-10-03'], [$case->range()->fromValue(), $case->range()->toValue()]);
    }

    public function test_days_that_are_not_days_are_a_mistake_in_the_test(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource, ['someday', null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not a range of days');

        $case->range();
    }

    public function test_a_date_column_is_given_a_date_range(): void
    {
        $case = $this->caseFor(new AdvertisedRangeSource, ['2026-10-02', null]);

        $this->assertSame(FieldType::Date, $case->range(FieldType::Date)->type);
        $this->assertSame(FieldType::DateTime, $case->range()->type);
    }

    /** @param array{0: ?string, 1: ?string}|null $days */
    private function caseFor(SourceInterface $source, ?array $days): object
    {
        $case = new class ('ranges') extends SourceContractTestCase {
            public SourceInterface $given;

            /** @var array{0: ?string, 1: ?string}|null */
            public ?array $days = null;

            protected function source(): SourceInterface
            {
                return $this->given;
            }

            protected function rowCount(): int
            {
                return 5;
            }

            protected function sortColumn(): string
            {
                return 'id';
            }

            protected function rangeValues(): array
            {
                return $this->days === null ? [] : ['joined_at' => $this->range()];
            }

            public function range(FieldType $type = FieldType::DateTime): DateRange
            {
                return $this->days($this->days[0] ?? null, $this->days[1] ?? null, $type);
            }
        };

        $case->given = $source;
        $case->days = $days;

        return $case;
    }
}
