<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\Accepted;
use Hydra\Validation\Rules\Email;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Rules\PresentValue;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Rules\RequiredIf;
use Hydra\Validation\Rules\RequiredWith;
use Hydra\Validation\Rules\RequiredWithout;
use Hydra\Validation\Rules\Sometimes;
use Hydra\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Whether a value had to be supplied at all: the two rules that end a chain
 * early, the three that depend on another field, and the consent box.
 */
#[CoversTrait(PresentValue::class)]
#[CoversClass(Accepted::class)]
#[CoversClass(Nullable::class)]
#[CoversClass(RequiredIf::class)]
#[CoversClass(RequiredWith::class)]
#[CoversClass(RequiredWithout::class)]
#[CoversClass(Sometimes::class)]
final class PresenceRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator;
    }

    public function test_nullable_exempts_a_blank_field_from_the_rules_after_it(): void
    {
        $rules = ['bio' => [new Nullable, new MinLength(10)]];

        $this->assertTrue($this->validator->validate(['bio' => ''], $rules)->passes());
        $this->assertTrue($this->validator->validate(['bio' => null], $rules)->passes());
        $this->assertTrue($this->validator->validate([], $rules)->passes());
        $this->assertTrue($this->validator->validate(['bio' => 'short'], $rules)->fails());
    }

    public function test_nullable_keeps_the_blank_value_in_the_validated_subset(): void
    {
        // Clearing an optional column is a real edit; the empty string has to
        // reach the update, not be dropped as if it was never submitted.
        $result = $this->validator->validate(['bio' => ''], ['bio' => [new Nullable]]);

        $this->assertSame(['bio' => ''], $result->validated());
    }

    public function test_sometimes_exempts_only_a_field_that_was_not_submitted(): void
    {
        // A PATCH sending two of eight columns must not have the other six
        // judged as blank, but a column it does send is judged in full.
        $rules = ['email' => [new Sometimes, new Required, new Email]];

        $this->assertTrue($this->validator->validate([], $rules)->passes());
        $this->assertTrue($this->validator->validate(['email' => ''], $rules)->fails());
        $this->assertTrue($this->validator->validate(['email' => 'nope'], $rules)->fails());
        $this->assertTrue($this->validator->validate(['email' => 'ada@example.com'], $rules)->passes());
    }

    public function test_required_with_fires_only_once_a_companion_arrives(): void
    {
        $rules = ['city' => [new RequiredWith(['street'], 'Give a city too.')]];

        $this->assertTrue($this->validator->validate([], $rules)->passes());
        $this->assertTrue($this->validator->validate(['street' => ''], $rules)->passes());
        $this->assertSame('Give a city too.', $this->validator->validate(['street' => '1 Main'], $rules)->first('city'));
        $this->assertTrue($this->validator->validate(['street' => '1 Main', 'city' => 'Winnipeg'], $rules)->passes());
    }

    public function test_required_without_fires_when_the_alternative_is_missing(): void
    {
        $rules = [
            'email' => [new RequiredWithout(['phone'], 'Leave an email or a phone number.')],
            'phone' => [new RequiredWithout(['email'], 'Leave an email or a phone number.')],
        ];

        $this->assertTrue($this->validator->validate(['email' => 'ada@example.com'], $rules)->passes());
        $this->assertTrue($this->validator->validate(['phone' => '555'], $rules)->passes());

        $neither = $this->validator->validate([], $rules);

        $this->assertSame('Leave an email or a phone number.', $neither->first('email'));
        $this->assertSame('Leave an email or a phone number.', $neither->first('phone'));
    }

    public function test_required_if_fires_on_a_matching_value_only(): void
    {
        $rules = ['other_reason' => [new RequiredIf('reason', ['other'], 'Tell us more.')]];

        $this->assertTrue($this->validator->validate(['reason' => 'broken'], $rules)->passes());
        $this->assertSame('Tell us more.', $this->validator->validate(['reason' => 'other'], $rules)->first('other_reason'));
        $this->assertTrue($this->validator->validate(['reason' => 'other', 'other_reason' => 'x'], $rules)->passes());
    }

    public function test_required_if_compares_a_checkbox_on_its_string_form(): void
    {
        $rules = ['card' => [new RequiredIf('paying', [true])]];

        $this->assertTrue($this->validator->validate(['paying' => '1'], $rules)->fails());
        $this->assertTrue($this->validator->validate(['paying' => true], $rules)->fails());
        $this->assertTrue($this->validator->validate(['paying' => '0'], $rules)->passes());
        $this->assertTrue($this->validator->validate(['paying' => ['1']], $rules)->passes());
    }

    public function test_the_conditional_rules_read_their_companion_within_the_same_row(): void
    {
        $data = ['lines' => [['sku' => 'a', 'qty' => 2], ['sku' => 'b']]];
        $rules = ['lines.*.qty' => [new RequiredWith(['sku'], 'How many?')]];

        $result = $this->validator->validate($data, $rules);

        $this->assertNull($result->first('lines.0.qty'));
        $this->assertSame('How many?', $result->first('lines.1.qty'));
    }

    public function test_the_conditional_rules_refuse_an_empty_field_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RequiredWith([]);
    }

    public function test_required_without_refuses_an_empty_field_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RequiredWithout([]);
    }

    public function test_required_if_refuses_an_empty_value_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RequiredIf('reason', []);
    }

    public function test_accepted_takes_the_forms_a_checkbox_submits(): void
    {
        $rule = new Accepted;
        $context = new Context([], 'terms');

        $this->assertNull($rule->validate('on', $context));
        $this->assertNull($rule->validate('1', $context));
        $this->assertNull($rule->validate(1, $context));
        $this->assertNull($rule->validate(true, $context));
        $this->assertNull($rule->validate('yes', $context));
    }

    public function test_accepted_refuses_everything_else(): void
    {
        // An unticked box is absent from the submission entirely, which is the
        // null case, and "false" is what a JSON client sends instead.
        $rule = new Accepted;
        $context = new Context([], 'terms');

        $this->assertSame('This must be accepted.', $rule->validate(null, $context));
        $this->assertSame('This must be accepted.', $rule->validate('off', $context));
        $this->assertSame('This must be accepted.', $rule->validate(false, $context));
        $this->assertSame('This must be accepted.', $rule->validate(0, $context));
        $this->assertSame('This must be accepted.', $rule->validate(['1'], $context));
    }

    public function test_acceptance_ignores_case_and_padding(): void
    {
        $rules = ['terms' => [new Accepted]];

        $this->assertTrue($this->validator->validate(['terms' => ' YES '], $rules)->passes());
        $this->assertTrue($this->validator->validate(['terms' => "\tOn"], $rules)->passes());
    }

    public function test_required_if_matches_a_numeric_trigger_value(): void
    {
        $rules = ['seats' => [new RequiredIf('plan', [2])]];

        $this->assertTrue($this->validator->validate(['plan' => '2', 'seats' => '4'], $rules)->passes());
        $this->assertSame(
            'This field is required.',
            $this->validator->validate(['plan' => '2'], $rules)->first('seats'),
        );
    }
}
