<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Pattern;
use Hydra\Validation\Rules\Required;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Stringable;

final class RulesTest extends TestCase
{
    public function testRequiredFailsOnNullEmptyStringAndEmptyArray(): void
    {
        $rule = new Required;

        $this->assertSame('This field is required.', $rule->validate(null));
        $this->assertSame('This field is required.', $rule->validate(''));
        $this->assertSame('This field is required.', $rule->validate([]));
    }

    public function testRequiredAllowsFalsyButPresentValues(): void
    {
        // Regression guard: "0", 0, and false are present and must pass.
        $rule = new Required;

        $this->assertNull($rule->validate('0'));
        $this->assertNull($rule->validate(0));
        $this->assertNull($rule->validate(false));
        $this->assertNull($rule->validate('hello'));
    }

    public function testRequiredAllowsNonEmptyArray(): void
    {
        // Only the empty array is "missing"; a populated array is present.
        $rule = new Required;

        $this->assertNull($rule->validate(['a']));
        $this->assertNull($rule->validate([0]));
    }

    public function testRequiredCarriesItsOwnMessage(): void
    {
        $this->assertSame('Write something first.', (new Required('Write something first.'))->validate(''));
    }

    public function testMaxLengthIsMultibyteAware(): void
    {
        $rule = new MaxLength(3);

        $this->assertNull($rule->validate('abc'));
        $this->assertNull($rule->validate('héé')); // 3 characters, more than 3 bytes
        $this->assertSame('Must be 3 characters or fewer.', $rule->validate('abcd'));
    }

    public function testMaxLengthPassesAbsentValue(): void
    {
        // null is length zero; presence is Required's job, not MaxLength's.
        $this->assertNull((new MaxLength(10))->validate(null));
    }

    public function testMinLengthIsMultibyteAware(): void
    {
        $rule = new MinLength(3);

        $this->assertNull($rule->validate('abc'));
        $this->assertSame('Must be at least 3 characters.', $rule->validate('ab'));
        $this->assertSame('Must be at least 3 characters.', $rule->validate(''));
    }

    public function testMinLengthFailsAbsentValue(): void
    {
        // Deliberate asymmetry with MaxLength: null is length zero, so for any
        // positive minimum it fails rather than passing.
        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate(null));
    }

    public function testCustomMessagesOverrideDefaults(): void
    {
        $this->assertSame('too long', (new MaxLength(2, 'too long'))->validate('abc'));
        $this->assertSame('too short', (new MinLength(5, 'too short'))->validate('ab'));
    }

    public function testPatternMatches(): void
    {
        $rule = new Pattern('/^[a-z]+$/', 'letters only');

        $this->assertNull($rule->validate('abc'));
        $this->assertSame('letters only', $rule->validate('abc123'));
    }

    public function testPatternUsesDefaultMessage(): void
    {
        $this->assertSame(
            'This value is not in the expected format.',
            (new Pattern('/^[a-z]+$/'))->validate('123'),
        );
    }

    public function testPatternCoercesNonStringValues(): void
    {
        // The (string) cast is load-bearing: numbers stringify, null becomes ''.
        $digits = new Pattern('/^\d+$/');

        $this->assertNull($digits->validate(123));
        $this->assertSame('This value is not in the expected format.', $digits->validate(null));
    }

    public function testArrayInputFailsStringRulesWithoutWarning(): void
    {
        // HTTP input is attacker-shaped: `field[]=x` arrives as an array. A
        // (string) cast would warn and coerce to the literal "Array" (length
        // 5 — which passes MinLength(3)/MaxLength(10)!). Wrong-shaped input
        // must fail cleanly instead. failOnWarning in phpunit.xml pins the
        // "no warning" half of this contract.
        $array = ['x'];

        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate($array));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate($array));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate($array));
    }

    public function testObjectWithoutToStringFailsStringRules(): void
    {
        // A plain object would be a fatal TypeError under (string); it is
        // wrong-shaped client input, so it fails validation instead.
        $object = new \stdClass;

        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate($object));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate($object));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate($object));
    }

    public function testBooleanFailsStringRules(): void
    {
        // Booleans are never legitimate text input; casting would validate
        // the artifacts "1"/"" rather than anything the client sent.
        $this->assertSame('Must be at least 3 characters.', (new MinLength(3))->validate(true));
        $this->assertSame('Must be 10 characters or fewer.', (new MaxLength(10))->validate(false));
        $this->assertSame('This value is not in the expected format.', (new Pattern('/\A.+\z/'))->validate(true));
    }

    public function testStringableObjectValidatesByItsStringValue(): void
    {
        $value = new class implements Stringable {
            public function __toString(): string
            {
                return 'hello';
            }
        };

        $this->assertNull((new MinLength(3))->validate($value));
        $this->assertNull((new MaxLength(10))->validate($value));
        $this->assertNull((new Pattern('/\A[a-z]+\z/'))->validate($value));
        $this->assertSame('Must be at least 6 characters.', (new MinLength(6))->validate($value));
    }

    public function testNumericScalarsStillStringify(): void
    {
        // int/float remain valid text-shaped input (e.g. already-cast form data).
        $this->assertNull((new MinLength(3))->validate(1234));
        $this->assertNull((new MaxLength(10))->validate(12.5));
        $this->assertNull((new Pattern('/\A[\d.]+\z/'))->validate(12.5));
    }

    public function testEndOfStringAnchorRejectsTrailingNewline(): void
    {
        // With the old '/^.+@.+$/' idiom, PCRE's $ tolerates a trailing
        // newline, so "a@b\n" passes. \A/\z anchor the true string ends —
        // this pins the corrected README example.
        $legacy = new Pattern('/^.+@.+$/');
        $this->assertNull($legacy->validate("a@b\n")); // the trap the README warns about

        $anchored = new Pattern('/\A.+@.+\z/');
        $this->assertNull($anchored->validate('a@b'));
        $this->assertSame('This value is not in the expected format.', $anchored->validate("a@b\n"));
    }

    public function testPatternRejectsMalformedRegexAtConstruction(): void
    {
        // A bad pattern is a developer error, surfaced immediately — not a
        // silent per-value "invalid" plus a runtime warning at match time.
        $this->expectException(InvalidArgumentException::class);

        new Pattern('/[unclosed');
    }
}
