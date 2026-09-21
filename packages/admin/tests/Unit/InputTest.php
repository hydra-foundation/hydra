<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Flag;
use Hydra\Admin\Input;
use Hydra\Admin\InputType;
use Hydra\Http\ParsedBody;
use Hydra\Validation\Rules\MinLength;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Validator;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A form control declaration: its rules, and which values it will and will not
 * hand back to the template (never a password).
 */
#[CoversClass(Flag::class)]
#[CoversClass(InputType::class)]
#[CoversClass(Input::class)]
final class InputTest extends TestCase
{
    public function test_it_labels_itself_from_its_name(): void
    {
        $this->assertSame('User agent', Input::text('user_agent')->label());
        $this->assertSame('Agent', Input::text('user_agent')->labelled('Agent')->label());
    }

    public function test_only_required_marks_an_input_required(): void
    {
        $this->assertFalse(Input::text('username')->isRequired());
        $this->assertFalse(Input::password('password')->rules(new MinLength(8))->isRequired());
        $this->assertTrue(Input::text('username')->required()->isRequired());
    }

    public function test_an_optional_control_leads_with_nullable(): void
    {
        $optional = Input::password('password')->rules(new MinLength(8));
        $validator = new Validator;
        $rules = ['password' => $optional->ruleSet()];

        $this->assertInstanceOf(Nullable::class, $optional->ruleSet()[0]);
        $this->assertTrue($validator->validate(['password' => ''], $rules)->passes());
        $this->assertTrue($validator->validate(['password' => 'short'], $rules)->fails());
    }

    public function test_a_blank_required_control_is_still_checked(): void
    {
        $required = Input::text('username')->required()->rules(new MinLength(3));

        $this->assertCount(2, $required->ruleSet());
        $this->assertTrue((new Validator)->validate(['username' => ''], ['username' => $required->ruleSet()])->fails());
    }

    public function test_it_hands_back_the_stored_value_not_a_reading_of_it(): void
    {
        $select = Input::select('role', ['admin' => 'Administrator']);

        $this->assertSame('admin', $select->valueFrom(['role' => 'admin']));
    }

    public function test_a_password_is_never_handed_back(): void
    {
        $this->assertSame('', Input::password('password')->valueFrom(['password' => 'hunter2']));
    }

    public function test_a_missing_value_is_an_empty_string(): void
    {
        $this->assertSame('', Input::text('username')->valueFrom([]));
        $this->assertSame('', Input::text('username')->valueFrom(['username' => null]));
    }

    public function test_declarations_are_immutable(): void
    {
        $plain = Input::text('username');
        $required = $plain->required();

        $this->assertFalse($plain->isRequired());
        $this->assertTrue($required->isRequired());
        $this->assertSame(InputType::Text, $plain->type());
    }

    public function test_a_checkbox_is_required_by_being_ticked(): void
    {
        // Required would pass the "0" an unticked box submits, because "0" is
        // a value that was submitted. Accepted is what the asterisk means.
        $checkbox = Input::checkbox('terms')->required();
        $rules = ['terms' => $checkbox->ruleSet()];
        $validator = new Validator;

        $this->assertTrue($validator->validate(['terms' => '0'], $rules)->fails());
        $this->assertTrue($validator->validate(['terms' => '1'], $rules)->passes());
    }

    public function test_only_a_checkbox_can_be_drawn_as_a_switch(): void
    {
        $this->assertTrue(Input::checkbox('active')->asSwitch()->isSwitch());
        $this->assertFalse(Input::checkbox('active')->isSwitch());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only a checkbox can be drawn as a switch');

        Input::text('username')->asSwitch();
    }

    public function test_a_tick_is_read_from_whatever_the_column_holds(): void
    {
        $checkbox = Input::checkbox('active');

        $this->assertTrue($checkbox->isChecked(['active' => 1]));
        $this->assertTrue($checkbox->isChecked(['active' => '1']));
        $this->assertTrue($checkbox->isChecked(['active' => true]));
        $this->assertFalse($checkbox->isChecked(['active' => 0]));
        $this->assertFalse($checkbox->isChecked(['active' => '0']));
        $this->assertFalse($checkbox->isChecked(['active' => null]));
        $this->assertFalse($checkbox->isChecked([]));
    }

    public function test_an_unticked_box_submits_a_zero_rather_than_nothing(): void
    {
        // The one control whose absence is a value. Everything else reads back
        // as the trimmed string it was sent as.
        $this->assertSame('0', Input::checkbox('active')->submittedValue($this->body([])));
        $this->assertSame('1', Input::checkbox('active')->submittedValue($this->body(['active' => 'on'])));
        $this->assertSame('0', Input::checkbox('active')->submittedValue($this->body(['active' => '0'])));
        $this->assertSame('ada', Input::text('username')->submittedValue($this->body(['username' => '  ada  '])));
        $this->assertSame('', Input::text('username')->submittedValue($this->body([])));
    }

    /** @param array<string, string> $body */
    private function body(array $body): ParsedBody
    {
        return ParsedBody::fromRequest(
            (new Psr17Factory)->createServerRequest('POST', '/admin/users/new')->withParsedBody($body),
        );
    }
}
