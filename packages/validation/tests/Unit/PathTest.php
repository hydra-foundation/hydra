<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Dot-path reads and writes, and the expansion of a wildcard rule key into the
 * concrete paths the submission actually carries.
 */
#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    public function test_it_reads_a_nested_value(): void
    {
        $data = ['address' => ['city' => 'Winnipeg', 'postal' => null]];

        $this->assertSame('Winnipeg', Path::get($data, 'address.city'));
        $this->assertNull(Path::get($data, 'address.postal'));
        $this->assertNull(Path::get($data, 'address.country'));
    }

    public function test_has_separates_a_null_value_from_a_missing_key(): void
    {
        $data = ['address' => ['postal' => null]];

        $this->assertTrue(Path::has($data, 'address.postal'));
        $this->assertFalse(Path::has($data, 'address.country'));
        $this->assertFalse(Path::has($data, 'billing.postal'));
    }

    public function test_it_reads_through_a_numeric_index(): void
    {
        $data = ['items' => [['qty' => 2], ['qty' => 5]]];

        $this->assertSame(5, Path::get($data, 'items.1.qty'));
        $this->assertTrue(Path::has($data, 'items.0.qty'));
    }

    public function test_descending_into_a_scalar_is_missing_not_an_error(): void
    {
        $data = ['name' => 'Ada'];

        $this->assertNull(Path::get($data, 'name.first'));
        $this->assertFalse(Path::has($data, 'name.first'));
    }

    public function test_it_writes_a_nested_value_creating_the_containers(): void
    {
        $target = [];

        Path::set($target, 'items.0.qty', 3);
        Path::set($target, 'items.1.qty', 4);
        Path::set($target, 'name', 'Ada');

        $this->assertSame(['items' => [['qty' => 3], ['qty' => 4]], 'name' => 'Ada'], $target);
    }

    public function test_a_key_without_a_wildcard_expands_to_itself_even_when_absent(): void
    {
        // Required on a missing field has to still fire, so expansion cannot
        // filter a plain key down to what the input happens to carry.
        $this->assertSame(['name'], Path::expand([], 'name'));
        $this->assertSame(['address.city'], Path::expand([], 'address.city'));
    }

    public function test_a_wildcard_expands_to_one_path_per_entry(): void
    {
        $data = ['items' => [['qty' => 1], ['qty' => 2], ['qty' => 3]]];

        $this->assertSame(['items.0.qty', 'items.1.qty', 'items.2.qty'], Path::expand($data, 'items.*.qty'));
        $this->assertSame(['items.0', 'items.1', 'items.2'], Path::expand($data, 'items.*'));
    }

    public function test_a_wildcard_over_string_keys_expands_to_those_keys(): void
    {
        $data = ['prices' => ['cad' => 10, 'usd' => 7]];

        $this->assertSame(['prices.cad', 'prices.usd'], Path::expand($data, 'prices.*'));
    }

    public function test_a_wildcard_over_nothing_expands_to_nothing(): void
    {
        // The rule on the container itself is what should complain about a
        // missing basket; the per-entry rules have nothing to say.
        $this->assertSame([], Path::expand([], 'items.*.qty'));
        $this->assertSame([], Path::expand(['items' => 'nope'], 'items.*.qty'));
        $this->assertSame([], Path::expand(['items' => []], 'items.*.qty'));
    }

    public function test_nested_wildcards_expand_across_both_levels(): void
    {
        $data = ['orders' => [['lines' => [['sku' => 'a'], ['sku' => 'b']]]]];

        $this->assertSame(
            ['orders.0.lines.0.sku', 'orders.0.lines.1.sku'],
            Path::expand($data, 'orders.*.lines.*.sku'),
        );
    }

    public function test_a_leading_wildcard_expands_over_the_root(): void
    {
        $this->assertSame(['a', 'b'], Path::expand(['a' => 1, 'b' => 2], '*'));
    }
}
