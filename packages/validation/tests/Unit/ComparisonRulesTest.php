<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use Hydra\Validation\Rules\Callback;
use Hydra\Validation\Rules\Confirmed;
use Hydra\Validation\Rules\Different;
use Hydra\Validation\Rules\NotInList;
use Hydra\Validation\Rules\Same;
use Hydra\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The rules that need more than their own value: a companion field, a denied
 * set, or a closure the application supplies.
 */
#[CoversClass(Callback::class)]
#[CoversClass(Confirmed::class)]
#[CoversClass(Different::class)]
#[CoversClass(NotInList::class)]
#[CoversClass(Same::class)]
final class ComparisonRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator;
    }

    public function test_confirmed_compares_against_the_companion_field(): void
    {
        $rules = ['password' => [new Confirmed]];

        $this->assertTrue($this->validator->validate(
            ['password' => 'hunter2', 'password_confirmation' => 'hunter2'],
            $rules,
        )->passes());

        $this->assertSame(
            'The confirmation does not match.',
            $this->validator->validate(
                ['password' => 'hunter2', 'password_confirmation' => 'hunter3'],
                $rules,
            )->first('password'),
        );
    }

    public function test_confirmed_fails_when_the_confirmation_was_not_sent(): void
    {
        $this->assertTrue($this->validator->validate(['password' => 'hunter2'], ['password' => [new Confirmed]])->fails());
    }

    public function test_confirmed_keeps_the_error_under_the_field_it_is_declared_on(): void
    {
        // The message belongs under the input the person retypes, and the
        // confirmation itself must never reach validated().
        $result = $this->validator->validate(
            ['password' => 'a', 'password_confirmation' => 'b'],
            ['password' => [new Confirmed]],
        );

        $this->assertSame(['password' => 'The confirmation does not match.'], $result->errors());
    }

    public function test_confirmed_takes_its_own_suffix(): void
    {
        $rules = ['email' => [new Confirmed('_again')]];

        $this->assertTrue($this->validator->validate(
            ['email' => 'ada@example.com', 'email_again' => 'ada@example.com'],
            $rules,
        )->passes());
    }

    public function test_confirmed_stays_within_its_row(): void
    {
        $data = ['users' => [
            ['password' => 'a', 'password_confirmation' => 'a'],
            ['password' => 'b', 'password_confirmation' => 'c'],
        ]];

        $result = $this->validator->validate($data, ['users.*.password' => [new Confirmed]]);

        $this->assertNull($result->first('users.0.password'));
        $this->assertNotNull($result->first('users.1.password'));
    }

    public function test_same_and_different_compare_identically_not_loosely(): void
    {
        $this->assertTrue($this->validator->validate(
            ['a' => '1', 'b' => 1],
            ['a' => [new Same('b')]],
        )->fails());

        $this->assertTrue($this->validator->validate(
            ['a' => '1', 'b' => '1'],
            ['a' => [new Same('b')]],
        )->passes());
    }

    public function test_different_refuses_a_repeat_of_the_field_it_names(): void
    {
        $rules = ['new_password' => [new Different('current_password', 'Pick a password you have not used.')]];

        $this->assertSame(
            'Pick a password you have not used.',
            $this->validator->validate(['current_password' => 'x', 'new_password' => 'x'], $rules)->first('new_password'),
        );
        $this->assertTrue($this->validator->validate(['current_password' => 'x', 'new_password' => 'y'], $rules)->passes());
    }

    public function test_not_in_list_refuses_a_reserved_value(): void
    {
        $rule = new NotInList(['admin', 'root'], 'That name is reserved.');
        $context = new Context([], 'username');

        $this->assertSame('That name is reserved.', $rule->validate('admin', $context));
        $this->assertNull($rule->validate('ada', $context));
        $this->assertNull($rule->validate('ADMIN', $context));
    }

    public function test_not_in_list_can_ignore_case(): void
    {
        $rule = new NotInList(['admin'], caseSensitive: false);

        $this->assertNotNull($rule->validate('ADMIN', new Context([], 'username')));
    }

    public function test_not_in_list_refuses_an_empty_denied_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotInList([]);
    }

    public function test_callback_runs_the_check_the_application_supplied(): void
    {
        $rule = new Callback(static fn (mixed $value): bool => is_int($value) && $value % 2 === 0, 'Must be even.');
        $context = new Context([], 'seats');

        $this->assertNull($rule->validate(4, $context));
        $this->assertSame('Must be even.', $rule->validate(5, $context));
    }

    public function test_callback_is_handed_the_context_too(): void
    {
        $rule = new Callback(
            static fn (mixed $value, Context $context): bool => $value === $context->sibling('expected'),
            'Mismatch.',
        );

        $this->assertTrue($this->validator->validate(
            ['answer' => '42', 'expected' => '42'],
            ['answer' => [$rule]],
        )->passes());
    }

    public function test_not_in_list_has_nothing_to_say_about_a_value_it_cannot_compare(): void
    {
        // An array is genuinely not one of the denied strings. Refusing it is
        // the type rules' job, and claiming "reserved" here would be a lie.
        $this->assertNull((new NotInList(['admin']))->validate(['admin'], new Context([], 'username')));
    }

    public function test_same_takes_a_message_of_its_own(): void
    {
        $rule = new Same('password', 'Both must match.');
        $context = new Context(['password' => 'a', 'confirm' => 'b'], 'confirm');

        $this->assertSame('Both must match.', $rule->validate('b', $context));
    }

    public function test_a_denied_number_is_compared_as_text(): void
    {
        $rule = new NotInList([5]);
        $context = new Context([], 'code');

        $this->assertSame('That value is not available.', $rule->validate('5', $context));
        $this->assertSame('That value is not available.', $rule->validate(5, $context));
    }

    public function test_a_case_sensitive_list_denies_only_the_spelling_it_was_given(): void
    {
        $rule = new NotInList(['Admin']);
        $context = new Context([], 'username');

        $this->assertNull($rule->validate('admin', $context));
        $this->assertSame('That value is not available.', $rule->validate('Admin', $context));
    }

    public function test_a_case_insensitive_list_denies_every_spelling(): void
    {
        $rule = new NotInList(['Admin'], null, false);
        $context = new Context([], 'username');

        $this->assertSame('That value is not available.', $rule->validate('ADMIN', $context));
    }
}
