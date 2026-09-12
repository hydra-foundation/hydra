<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Input;
use Hydra\Admin\InputType;
use Hydra\Validation\Rules\MinLength;
use PHPUnit\Framework\TestCase;

/**
 * A form control declaration: its rules, and which values it will and will not
 * hand back to the template (never a password).
 */
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

    public function test_a_blank_optional_control_is_exempt_from_its_rules(): void
    {
        $optional = Input::password('password')->rules(new MinLength(8));

        $this->assertSame([], $optional->rulesFor(''));
        $this->assertSame([], $optional->rulesFor(null));
        $this->assertCount(1, $optional->rulesFor('short'));
    }

    public function test_a_blank_required_control_is_still_checked(): void
    {
        $this->assertCount(2, Input::text('username')->required()->rules(new MinLength(3))->rulesFor(''));
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
}
