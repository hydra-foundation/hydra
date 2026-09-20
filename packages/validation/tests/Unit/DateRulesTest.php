<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\Date;
use Hydra\Validation\Rules\DateRange;
use Hydra\Validation\Rules\ParsesDates;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Dates, read strictly. PHP's parser rolls an impossible day into the next
 * month, which would let a date rule accept 2026-02-31 and store the 3rd of
 * March.
 */
#[CoversTrait(ParsesDates::class)]
#[CoversClass(Date::class)]
#[CoversClass(DateRange::class)]
final class DateRulesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context([], 'field');
    }

    public function test_date_accepts_its_format_and_nothing_else(): void
    {
        $rule = new Date;

        $this->assertNull($rule->validate('2026-09-20', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('20/09/2026', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('2026-09-20 10:00', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate(null, $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate(20260920, $this->context));
    }

    public function test_date_refuses_a_day_the_month_does_not_have(): void
    {
        $rule = new Date;

        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('2026-02-31', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('2026-13-01', $this->context));
        $this->assertNull($rule->validate('2024-02-29', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d.', $rule->validate('2026-02-29', $this->context));
    }

    public function test_date_takes_its_own_format(): void
    {
        $rule = new Date('Y-m-d H:i');

        $this->assertNull($rule->validate('2026-09-20 14:30', $this->context));
        $this->assertSame('Enter a date in the format Y-m-d H:i.', $rule->validate('2026-09-20', $this->context));
    }

    public function test_date_range_bounds_both_ends_inclusively(): void
    {
        $rule = new DateRange('2026-01-01', '2026-12-31');

        $this->assertNull($rule->validate('2026-01-01', $this->context));
        $this->assertNull($rule->validate('2026-12-31', $this->context));
        $this->assertSame('Enter a date between 2026-01-01 and 2026-12-31.', $rule->validate('2025-12-31', $this->context));
        $this->assertSame('Enter a date between 2026-01-01 and 2026-12-31.', $rule->validate('2027-01-01', $this->context));
    }

    public function test_date_range_takes_one_bound_at_a_time(): void
    {
        $this->assertSame(
            'Enter a date no earlier than 2026-01-01.',
            (new DateRange(min: '2026-01-01'))->validate('2025-06-01', $this->context),
        );
        $this->assertSame(
            'Enter a date no later than 2026-01-01.',
            (new DateRange(max: '2026-01-01'))->validate('2026-06-01', $this->context),
        );
    }

    public function test_date_range_reads_a_relative_bound(): void
    {
        // An age gate is the case this exists for.
        $rule = new DateRange(max: '-18 years');

        $this->assertNull($rule->validate('1990-01-01', $this->context));
        $this->assertNotNull($rule->validate(date('Y-m-d'), $this->context));
    }

    public function test_date_range_compares_a_same_day_value_at_midnight(): void
    {
        // Without the format's unparsed fields being zeroed, today's date
        // would carry the current time and fall outside a bound of 'today'.
        $this->assertNull((new DateRange(max: 'today'))->validate(date('Y-m-d'), $this->context));
    }

    public function test_date_range_still_refuses_a_malformed_date(): void
    {
        $this->assertNotNull((new DateRange(min: '2026-01-01'))->validate('nonsense', $this->context));
    }

    public function test_date_range_needs_at_least_one_bound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateRange;
    }

    public function test_date_range_refuses_inverted_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateRange('2026-12-31', '2026-01-01');
    }

    public function test_date_range_refuses_a_bound_it_cannot_read(): void
    {
        try {
            new DateRange(min: 'the day before yesteryear');
            $this->fail('An unreadable bound should not build a rule.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertInstanceOf(Exception::class, $e->getPrevious());
        }
    }

    public function test_date_range_allows_equal_bounds(): void
    {
        $rule = new DateRange('2026-01-01', '2026-01-01');

        $this->assertNull($rule->validate('2026-01-01', $this->context));
    }

    public function test_a_lower_bound_on_its_own_still_refuses_an_earlier_date(): void
    {
        $rule = new DateRange('2026-01-01');

        $this->assertSame(
            'Enter a date no earlier than 2026-01-01.',
            $rule->validate('2025-12-31', $this->context),
        );
    }

    public function test_the_date_rules_take_a_message_of_their_own(): void
    {
        $this->assertSame('Bad date.', (new Date('Y-m-d', 'Bad date.'))->validate('nope', $this->context));
        $this->assertSame(
            'Too early.',
            (new DateRange('2026-01-01', null, 'Y-m-d', 'Too early.'))->validate('2025-01-01', $this->context),
        );
    }

    public function test_an_upper_bound_on_its_own_still_refuses_an_unreadable_date(): void
    {
        $rule = new DateRange(max: '2026-12-31');

        $this->assertSame('Enter a date no later than 2026-12-31.', $rule->validate('nope', $this->context));
    }

    public function test_a_date_that_is_not_text_is_not_a_date(): void
    {
        $this->assertSame(
            'Enter a date in the format Y-m-d.',
            (new Date)->validate(20260101, $this->context),
        );
    }
}
