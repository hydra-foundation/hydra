<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\InList;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Pattern;
use Hydra\Validation\Rules\Range;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Rules\PresentValue;
use Hydra\Validation\Rules\TextualValue;
use Hydra\Validation\Rules\WholeNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Every shipped rule against the values a request actually carries, including
 * the non-string ones (arrays, objects, booleans) that must fail a string rule
 * rather than warn or fatal on the way.
 */
#[CoversTrait(PresentValue::class)]
#[CoversTrait(TextualValue::class)]
#[CoversTrait(WholeNumber::class)]
#[CoversClass(InList::class)]
#[CoversClass(MaxLength::class)]
#[CoversClass(MinLength::class)]
#[CoversClass(Pattern::class)]
#[CoversClass(Range::class)]
#[CoversClass(Required::class)]
final class RulesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context([], 'field');
    }

    public function test_required_fails_on_null_empty_string_and_empty_array(): void
    {
        $rule = new Required;

        $this->assertSame('This field is required.', $rule->validate(null, $this->context));
        $this->assertSame('This field is required.', $rule->validate('', $this->context));
        $this->assertSame('This field is required.', $rule->validate([], $this->context));
    }

    public function test_required_allows_falsy_but_present_values(): void
    {
        // Regression guard: "0", 0, and false are present and must pass.
        $rule = new Required;

        $this->assertNull($rule->validate('0', $this->context));
        $this->assertNull($rule->validate(0, $this->context));
        $this->assertNull($rule->validate(false, $this->context));
        $this->assertNull($rule->validate('hello', $this->context));
    }

    public function test_required_allows_non_empty_array(): void
    {
        // Only the empty array is "missing"; a populated array is present.
        $rule = new Required;

        $this->assertNull($rule->validate(['a'], $this->context));
        $this->assertNull($rule->validate([0], $this->context));
    }

    public function test_required_carries_its_own_message(): void
    {
        $this->assertSame('Write something first.', (new Required('Write something first.'))->validate('', $this->context));
    }

    public function test_max_length_is_multibyte_aware(): void
    {
        $rule = new MaxLength(3);

        $this->assertNull($rule->validate('abc', $this->context));
        $this->assertNull($rule->validate('héé', $this->context)); // 3 characters, more than 3 bytes
        $this->assertSame('Must be 3 characters or fewer.', $rule->validate('abcd', $this->context));
    }

    public function test_max_length_passes_absent_value(): void
    {
        // null is length zero; presence is Required's job, not MaxLength's.
        $this->assertNull((new MaxLength(10))->validate(null, $this->context));
    }

    public function test_min_length_is_multibyte_aware(): void
    {
        $rule = new MinLength(3);

        $this->assertNull($rule->validate('abc', $this->context));
        $this->assertSame('Must be at least 3 characters.', $rule->validate('ab', $this->context));
        $this->assertSame('Must be at least 3 characters.', $rule->validate('', $this->context));

        // Characters, not bytes. 'aou' with umlauts is three characters and six
        // bytes, so a byte count would call it long enough for a minimum of
        // four and let a password half the required length through.
        $this->assertSame('Must be at least 4 characters.', (new MinLength(4))->validate("\u{e5}\u{e4}\u{f6}", $this->context));
        $this->assertNull((new MinLength(3))->validate("\u{e5}\u{e4}\u{f6}", $this->context));
    }

    public function test_min_length_fails_absent_value(): void
    {
        // Deliberate asymmetry with MaxLength: null is length zero, so for any
        // positive minimum it fails rather than passing.
        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate(null, $this->context));
    }

    public function test_custom_messages_override_defaults(): void
    {
        // Every rule takes one, and a rule that ignored it would show a visitor
        // the framework's wording in place of the application's.
        $this->assertSame('too long', (new MaxLength(2, 'too long'))->validate('abc', $this->context));
        $this->assertSame('too short', (new MinLength(5, 'too short'))->validate('ab', $this->context));
        $this->assertSame('pick a page', (new Range(1, 10, 'pick a page'))->validate(99, $this->context));
        $this->assertSame('unknown role', (new InList(['admin'], 'unknown role'))->validate('root', $this->context));
    }

    public function test_pattern_matches(): void
    {
        $rule = new Pattern('/^[a-z]+$/', 'letters only');

        $this->assertNull($rule->validate('abc', $this->context));
        $this->assertSame('letters only', $rule->validate('abc123', $this->context));
    }

    public function test_pattern_uses_default_message(): void
    {
        $this->assertSame(
            'This value is not in the expected format.',
            (new Pattern('/^[a-z]+$/'))->validate('123', $this->context),
        );
    }

    public function test_pattern_coerces_non_string_values(): void
    {
        // The (string) cast is load-bearing: numbers stringify, null becomes ''.
        $digits = new Pattern('/^\d+$/');

        $this->assertNull($digits->validate(123, $this->context));
        $this->assertSame('This value is not in the expected format.', $digits->validate(null, $this->context));
    }

    public function test_array_input_fails_string_rules_without_warning(): void
    {
        // HTTP input is attacker-shaped: `field[]=x` arrives as an array. A
        // (string) cast would warn and coerce to the literal "Array" (length
        // 5, which passes MinLength(3)/MaxLength(10)). Wrong-shaped input
        // must fail cleanly instead. failOnWarning in phpunit.xml pins the
        // "no warning" half of this contract.
        $array = ['x'];

        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate($array, $this->context));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate($array, $this->context));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate($array, $this->context));
    }

    public function test_object_without_to_string_fails_string_rules(): void
    {
        // A plain object would be a fatal TypeError under (string); it is
        // wrong-shaped client input, so it fails validation instead.
        $object = new \stdClass;

        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate($object, $this->context));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate($object, $this->context));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate($object, $this->context));
    }

    public function test_boolean_fails_string_rules(): void
    {
        // Booleans are never legitimate text input; casting would validate
        // the artifacts "1"/"" rather than anything the client sent.
        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate(true, $this->context));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate(false, $this->context));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate(true, $this->context));
    }

    public function test_stringable_object_validates_by_its_string_value(): void
    {
        $value = new class implements Stringable {
            public function __toString(): string
            {
                return 'hello';
            }
        };

        $this->assertNull((new MinLength(3))->validate($value, $this->context));
        $this->assertNull((new MaxLength(10))->validate($value, $this->context));
        $this->assertNull((new Pattern('/\A[a-z]+\z/'))->validate($value, $this->context));
        $this->assertSame('Must be at least 6 characters.', (new MinLength(6))->validate($value, $this->context));
    }

    public function test_numeric_scalars_still_stringify(): void
    {
        // int/float remain valid text-shaped input (e.g. already-cast form data).
        $this->assertNull((new MinLength(3))->validate(1234, $this->context));
        $this->assertNull((new MaxLength(10))->validate(12.5, $this->context));
        $this->assertNull((new Pattern('/\A[\d.]+\z/'))->validate(12.5, $this->context));
    }

    public function test_end_of_string_anchor_rejects_trailing_newline(): void
    {
        // With the old '/^.+@.+$/' idiom, PCRE's $ tolerates a trailing
        // newline, so "a@b\n" passes. \A/\z anchor the true string ends;
        // this pins the corrected README example.
        $legacy = new Pattern('/^.+@.+$/');
        $this->assertNull($legacy->validate("a@b\n", $this->context)); // the trap the README warns about

        $anchored = new Pattern('/\A.+@.+\z/');
        $this->assertNull($anchored->validate('a@b', $this->context));
        $this->assertSame('This value is not in the expected format.', $anchored->validate("a@b\n", $this->context));
    }

    public function test_pattern_rejects_malformed_regex_at_construction(): void
    {
        // A bad pattern is a developer error, surfaced immediately rather than as a
        // silent per-value "invalid" plus a runtime warning at match time.
        $this->expectException(InvalidArgumentException::class);

        new Pattern('/[unclosed');
    }

    public function test_range_accepts_within_bounds_inclusive(): void
    {
        $rule = new Range(1, 100);

        $this->assertNull($rule->validate(1, $this->context));
        $this->assertNull($rule->validate(100, $this->context));
        $this->assertNull($rule->validate(50, $this->context));
    }

    public function test_range_rejects_outside_bounds(): void
    {
        $rule = new Range(1, 100);

        $this->assertSame('Must be a whole number between 1 and 100.', $rule->validate(0, $this->context));
        $this->assertSame('Must be a whole number between 1 and 100.', $rule->validate(101, $this->context));
        $this->assertSame('Must be a whole number between 1 and 100.', $rule->validate(-5, $this->context));
    }

    public function test_range_reads_digit_strings_because_query_input_is_text(): void
    {
        $rule = new Range(1, 100);

        $this->assertNull($rule->validate('50', $this->context));
        $this->assertNull($rule->validate(' 50 ', $this->context));
        $this->assertSame('Must be a whole number between 1 and 100.', $rule->validate('101', $this->context));
    }

    public function test_range_rejects_what_is_not_a_whole_number(): void
    {
        // The trap this rule exists to avoid: coercing "abc" to 0 would pass a
        // min of 0, and flooring "2.5" would accept a value nobody sent.
        $rule = new Range(0, 100);

        $this->assertNotNull($rule->validate('abc', $this->context));
        $this->assertNotNull($rule->validate('2.5', $this->context));
        $this->assertNotNull($rule->validate('', $this->context));
        $this->assertNotNull($rule->validate(null, $this->context));
        $this->assertNotNull($rule->validate([], $this->context));
        $this->assertNotNull($rule->validate(true, $this->context));
    }

    public function test_in_list_accepts_only_what_it_lists(): void
    {
        $rule = new InList(['asc', 'desc']);

        $this->assertNull($rule->validate('asc', $this->context));
        $this->assertNull($rule->validate('desc', $this->context));
        $this->assertSame('Must be one of: asc, desc.', $rule->validate('sideways', $this->context));
        $this->assertSame('Must be one of: asc, desc.', $rule->validate('', $this->context));
    }

    public function test_in_list_compares_strictly_on_the_string_form(): void
    {
        // A loose comparison would let 0 match "asc" and true match "1", the
        // classic way an allow-list stops being one.
        $this->assertNotNull((new InList(['asc']))->validate(0, $this->context));
        $this->assertNotNull((new InList(['1']))->validate(true, $this->context));
        $this->assertNull((new InList([1, 2]))->validate('1', $this->context));
    }

    public function test_in_list_rejects_an_empty_allowed_set_at_construction(): void
    {
        // An empty set rejects everything, which is a caller passing through a
        // list it meant to populate. A developer error, surfaced immediately.
        $this->expectException(InvalidArgumentException::class);

        new InList([]);
    }
}
