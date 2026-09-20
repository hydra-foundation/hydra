<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a rule sees beyond its own value, and the scoping that lets one rule
 * object name a companion field whether the form is flat or repeated.
 */
#[CoversClass(Context::class)]
final class ContextTest extends TestCase
{
    public function test_it_reports_the_field_under_validation(): void
    {
        $context = new Context(['name' => 'Ada'], 'name');

        $this->assertSame('name', $context->field());
        $this->assertSame(['name' => 'Ada'], $context->data());
    }

    public function test_it_reads_any_path_from_the_root(): void
    {
        $context = new Context(['address' => ['city' => 'Winnipeg']], 'name');

        $this->assertSame('Winnipeg', $context->value('address.city'));
        $this->assertTrue($context->has('address.city'));
        $this->assertFalse($context->has('address.country'));
    }

    public function test_a_sibling_of_a_top_level_field_is_read_from_the_root(): void
    {
        $context = new Context(['password' => 'secret', 'password_confirmation' => 'secret'], 'password');

        $this->assertSame('secret', $context->sibling('password_confirmation'));
        $this->assertTrue($context->hasSibling('password_confirmation'));
        $this->assertSame('password_confirmation', $context->siblingPath('password_confirmation'));
    }

    public function test_a_sibling_of_a_nested_field_stays_in_its_own_row(): void
    {
        // The same rule object walks every row, and must not compare row 1's
        // quantity against row 0's limit.
        $data = ['items' => [['qty' => 1, 'max' => 2], ['qty' => 9, 'max' => 3]]];

        $this->assertSame(2, (new Context($data, 'items.0.qty'))->sibling('max'));
        $this->assertSame(3, (new Context($data, 'items.1.qty'))->sibling('max'));
        $this->assertSame('items.1.max', (new Context($data, 'items.1.qty'))->siblingPath('max'));
    }

    public function test_a_missing_sibling_reads_as_null(): void
    {
        $context = new Context(['password' => 'secret'], 'password');

        $this->assertNull($context->sibling('password_confirmation'));
        $this->assertFalse($context->hasSibling('password_confirmation'));
    }
}
