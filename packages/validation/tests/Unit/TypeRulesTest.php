<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\ArrayValue;
use Hydra\Validation\Rules\Boolean;
use Hydra\Validation\Rules\CountRange;
use Hydra\Validation\Rules\Integer;
use Hydra\Validation\Rules\Numeric;
use Hydra\Validation\Rules\NumericRange;
use Hydra\Validation\Rules\NumericValue;
use Hydra\Validation\Rules\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * What shape a value is. Everything arrives from HTTP as a string or an array,
 * so each of these decides what it will read out of text and what it refuses
 * rather than coerce.
 */
#[CoversTrait(NumericValue::class)]
#[CoversClass(ArrayValue::class)]
#[CoversClass(Boolean::class)]
#[CoversClass(CountRange::class)]
#[CoversClass(Integer::class)]
#[CoversClass(Numeric::class)]
#[CoversClass(NumericRange::class)]
#[CoversClass(Text::class)]
final class TypeRulesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context([], 'field');
    }

    public function test_text_accepts_only_a_string(): void
    {
        $rule = new Text;

        $this->assertNull($rule->validate('', $this->context));
        $this->assertNull($rule->validate('Ada', $this->context));
        $this->assertSame('Must be text.', $rule->validate(1, $this->context));
        $this->assertSame('Must be text.', $rule->validate(['a'], $this->context));
        $this->assertSame('Must be text.', $rule->validate(null, $this->context));
    }

    public function test_integer_reads_a_digit_string_and_refuses_a_fraction(): void
    {
        $rule = new Integer;

        $this->assertNull($rule->validate(42, $this->context));
        $this->assertNull($rule->validate('42', $this->context));
        $this->assertNull($rule->validate(' -42 ', $this->context));
        $this->assertSame('Must be a whole number.', $rule->validate('4.2', $this->context));
        $this->assertSame('Must be a whole number.', $rule->validate(4.2, $this->context));
        $this->assertSame('Must be a whole number.', $rule->validate('', $this->context));
        $this->assertSame('Must be a whole number.', $rule->validate(true, $this->context));
    }

    public function test_numeric_accepts_a_fraction(): void
    {
        $rule = new Numeric;

        $this->assertNull($rule->validate('19.99', $this->context));
        $this->assertNull($rule->validate(19.99, $this->context));
        $this->assertNull($rule->validate(-3, $this->context));
        $this->assertNull($rule->validate('1e3', $this->context));
        $this->assertSame('Must be a number.', $rule->validate('19.99 CAD', $this->context));
        $this->assertSame('Must be a number.', $rule->validate('', $this->context));
        $this->assertSame('Must be a number.', $rule->validate(null, $this->context));
    }

    public function test_numeric_refuses_nan_and_infinity(): void
    {
        // Both survive is_numeric and then make every comparison downstream
        // answer false, including the bounds check in NumericRange.
        $rule = new Numeric;

        $this->assertSame('Must be a number.', $rule->validate(NAN, $this->context));
        $this->assertSame('Must be a number.', $rule->validate(INF, $this->context));
    }

    public function test_numeric_range_bounds_a_price(): void
    {
        $rule = new NumericRange(0.01, 999.99);

        $this->assertNull($rule->validate('0.01', $this->context));
        $this->assertNull($rule->validate('999.99', $this->context));
        $this->assertSame('Must be a number between 0.01 and 999.99.', $rule->validate('0', $this->context));
        $this->assertSame('Must be a number between 0.01 and 999.99.', $rule->validate('1000', $this->context));
        $this->assertSame('Must be a number between 0.01 and 999.99.', $rule->validate('free', $this->context));
    }

    public function test_numeric_range_refuses_inverted_bounds_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NumericRange(10, 1);
    }

    public function test_boolean_reads_every_form_a_form_submits(): void
    {
        $rule = new Boolean;

        foreach ([true, false, 1, 0, '1', '0', 'on', 'off', 'yes', 'no', 'true', 'FALSE', ''] as $value) {
            $this->assertNull($rule->validate($value, $this->context), var_export($value, true));
        }
    }

    public function test_boolean_refuses_what_is_not_a_yes_or_a_no(): void
    {
        $rule = new Boolean;

        $this->assertSame('Must be true or false.', $rule->validate('maybe', $this->context));
        $this->assertSame('Must be true or false.', $rule->validate(2, $this->context));
        $this->assertSame('Must be true or false.', $rule->validate(null, $this->context));
        $this->assertSame('Must be true or false.', $rule->validate([], $this->context));
    }

    public function test_array_value_accepts_only_an_array(): void
    {
        $rule = new ArrayValue;

        $this->assertNull($rule->validate([], $this->context));
        $this->assertNull($rule->validate(['a'], $this->context));
        $this->assertSame('Must be a list of values.', $rule->validate('a', $this->context));
        $this->assertSame('Must be a list of values.', $rule->validate(null, $this->context));
    }

    public function test_count_range_bounds_how_many_entries_arrived(): void
    {
        $rule = new CountRange(1, 3);

        $this->assertNull($rule->validate(['a'], $this->context));
        $this->assertNull($rule->validate(['a', 'b', 'c'], $this->context));
        $this->assertSame('Must have between 1 and 3 entries.', $rule->validate([], $this->context));
        $this->assertSame('Must have between 1 and 3 entries.', $rule->validate(['a', 'b', 'c', 'd'], $this->context));
        $this->assertSame('Must have between 1 and 3 entries.', $rule->validate('abc', $this->context));
    }

    public function test_count_range_leaves_the_upper_bound_open(): void
    {
        $rule = new CountRange(2);

        $this->assertNull($rule->validate(range(1, 500), $this->context));
        $this->assertSame('Must have at least 2 entries.', $rule->validate(['a'], $this->context));
    }

    public function test_count_range_refuses_impossible_bounds_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CountRange(3, 1);
    }

    public function test_count_range_refuses_a_negative_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CountRange(-1);
    }

    public function test_boolean_reads_a_padded_and_capitalised_value(): void
    {
        $rule = new Boolean;

        $this->assertNull($rule->validate(' TRUE ', $this->context));
        $this->assertNull($rule->validate("\tOff\n", $this->context));
    }

    public function test_count_range_allows_a_minimum_of_zero(): void
    {
        $this->assertNull((new CountRange(0, 2))->validate([], $this->context));
    }

    public function test_count_range_allows_equal_bounds(): void
    {
        $rule = new CountRange(2, 2);

        $this->assertNull($rule->validate(['a', 'b'], $this->context));
        $this->assertSame('Must have between 2 and 2 entries.', $rule->validate(['a'], $this->context));
    }

    public function test_count_range_takes_a_message_of_its_own(): void
    {
        $this->assertSame('Pick two.', (new CountRange(2, 2, 'Pick two.'))->validate([], $this->context));
        $this->assertSame('Pick some.', (new CountRange(1, null, 'Pick some.'))->validate([], $this->context));
    }

    public function test_numeric_range_allows_equal_bounds(): void
    {
        $this->assertNull((new NumericRange(5, 5))->validate(5, $this->context));
    }

    public function test_numeric_range_takes_a_message_of_its_own(): void
    {
        $this->assertSame('Out of range.', (new NumericRange(1, 5, 'Out of range.'))->validate(9, $this->context));
    }

    public function test_numeric_range_refuses_text_rather_than_reading_it_as_zero(): void
    {
        // PHP reads null against an int as a bool, so a lower bound of zero is
        // the case where an unread value would pass the range check.
        $rule = new NumericRange(0, 5);

        $this->assertSame('Must be a number between 0 and 5.', $rule->validate('abc', $this->context));
    }

    public function test_a_number_may_arrive_padded(): void
    {
        $this->assertNull((new Numeric)->validate(' 1.5 ', $this->context));
    }

    public function test_a_magnitude_php_cannot_hold_is_not_a_number(): void
    {
        $this->assertNotNull((new Numeric)->validate('1e400', $this->context));
    }
}
