<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Testing\SourceContractTestCase;
use Hydra\Admin\Tests\Support\AdvertisedFilterSource;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * That the filter half of the published case fails when it should.
 *
 * A contract case is only worth what it refuses, and this one refuses a state
 * nothing else in the toolchain can see: `admin:check` reads the description
 * and agrees with it, so a source that advertises a filter and never applies it
 * is green everywhere until something runs the filter. The cases below are that
 * source, run both ways round.
 */
#[CoversClass(SourceContractTestCase::class)]
final class SourceContractFiltersTest extends TestCase
{
    public function test_a_source_that_ignores_the_filter_it_advertises_fails_the_case(): void
    {
        $case = $this->caseFor(new AdvertisedFilterSource(applies: false), ['role' => 'admin']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('returned every row');

        $case->test_each_filter_narrows_what_comes_back();
    }

    public function test_an_advertised_filter_with_no_value_to_run_it_with_is_reported(): void
    {
        $case = $this->caseFor(new AdvertisedFilterSource, []);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('filterValues() gives no value for it');

        $case->test_every_filter_the_source_advertises_has_a_value_to_check_it_with();
    }

    public function test_a_value_that_matches_no_row_is_reported_rather_than_read_as_narrowing(): void
    {
        $case = $this->caseFor(new AdvertisedFilterSource, ['role' => 'nobody-holds-this']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('matched no row');

        $case->test_each_filter_narrows_what_comes_back();
    }

    public function test_a_source_that_applies_what_it_advertises_passes_both(): void
    {
        $source = new AdvertisedFilterSource;
        $case = $this->caseFor($source, ['role' => 'admin']);

        $case->test_every_filter_the_source_advertises_has_a_value_to_check_it_with();
        $case->test_each_filter_narrows_what_comes_back();

        // Both returned rather than threw, and this is why: two of the five.
        $this->assertCount(2, $source->page(new Criteria(filters: ['role' => 'admin']))->rows);
    }

    /**
     * PHPUnit's constructor is final, so the fixture is assigned rather than
     * injected, and the hooks read the properties.
     *
     * @param array<string, string> $filters
     */
    private function caseFor(SourceInterface $source, array $filters): SourceContractTestCase
    {
        $case = new class ('filters') extends SourceContractTestCase {
            public SourceInterface $given;

            /** @var array<string, string> */
            public array $values = [];

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

            protected function filterValues(): array
            {
                return $this->values;
            }
        };

        $case->given = $source;
        $case->values = $filters;

        return $case;
    }
}
