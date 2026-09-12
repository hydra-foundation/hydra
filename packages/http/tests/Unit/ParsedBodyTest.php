<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\BadRequestException;
use Hydra\Http\ParsedBody;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * The typed reader over a request's parsed body: coercion per type, the default
 * on a miss, the cases that throw rather than guess, and the shapes PSR-7
 * allows a parsed body to be (null, an object) handled without a TypeError.
 */
final class ParsedBodyTest extends TestCase
{
    private function body(array|object|null $body): ParsedBody
    {
        $request = (new Psr17Factory)->createServerRequest('POST', '/')->withParsedBody($body);

        return ParsedBody::fromRequest($request);
    }

    public function test_string_reads_a_field(): void
    {
        $this->assertSame('Ada', $this->body(['name' => 'Ada'])->string('name'));
    }

    public function test_string_falls_back_to_default_when_absent(): void
    {
        $input = $this->body(['name' => 'Ada']);

        $this->assertSame('', $input->string('missing'));
        $this->assertSame('anon', $input->string('missing', 'anon'));
    }

    public function test_string_does_not_trim(): void
    {
        // Trimming is the caller's choice; the reader returns the value as sent.
        $this->assertSame('  spaced  ', $this->body(['x' => '  spaced  '])->string('x'));
    }

    public function test_string_keeps_falsy_zero(): void
    {
        $this->assertSame('0', $this->body(['x' => '0'])->string('x'));
    }

    public function test_string_defaults_when_field_is_an_array(): void
    {
        // name[] arrives as an array; stringifying it would be a TypeError.
        $this->assertSame('', $this->body(['name' => ['a', 'b']])->string('name'));
    }

    public function test_int_coerces_numeric_strings(): void
    {
        $this->assertSame(42, $this->body(['age' => '42'])->int('age'));
        $this->assertSame(0, $this->body(['age' => '0'])->int('age'));
    }

    public function test_int_defaults_on_non_numeric(): void
    {
        $this->assertNull($this->body(['age' => 'old'])->int('age'));
        $this->assertSame(-1, $this->body([])->int('age', -1));
        $this->assertNull($this->body(['age' => ''])->int('age'));
    }

    public function test_float_coerces_numeric_strings(): void
    {
        $this->assertSame(3.14, $this->body(['price' => '3.14'])->float('price'));
        $this->assertSame(42.0, $this->body(['price' => '42'])->float('price'));
        $this->assertSame(0.0, $this->body(['price' => '0'])->float('price'));
    }

    public function test_float_reads_a_real_float_from_a_json_body(): void
    {
        $this->assertSame(1.5, $this->body(['price' => 1.5])->float('price'));
    }

    public function test_float_defaults_on_miss_and_non_numeric(): void
    {
        $this->assertNull($this->body([])->float('price'));
        $this->assertSame(9.99, $this->body([])->float('price', 9.99));
        $this->assertNull($this->body(['price' => 'cheap'])->float('price'));
        $this->assertNull($this->body(['price' => ''])->float('price'));
        $this->assertNull($this->body(['price' => ['1.5']])->float('price'));
    }

    public function test_bool_accepts_explicit_true_forms(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', 'On', 1, true] as $form) {
            $this->assertTrue($this->body(['flag' => $form])->bool('flag'), var_export($form, true));
        }
    }

    public function test_bool_accepts_explicit_false_forms(): void
    {
        foreach (['false', 'FALSE', '0', 'no', 'off', 'Off', 0, false] as $form) {
            $this->assertFalse($this->body(['flag' => $form])->bool('flag'), var_export($form, true));
        }
    }

    public function test_bool_defaults_when_absent(): void
    {
        $this->assertNull($this->body([])->bool('flag'));
        $this->assertTrue($this->body([])->bool('flag', true));
        $this->assertFalse($this->body([])->bool('flag', false));
    }

    public function test_bool_throws_on_garbage(): void
    {
        $this->expectException(BadRequestException::class);

        $this->body(['flag' => 'maybe'])->bool('flag');
    }

    public function test_bool_throws_on_empty_string(): void
    {
        // "" is present but is neither an explicit true nor false form.
        $this->expectException(BadRequestException::class);

        $this->body(['flag' => ''])->bool('flag');
    }

    public function test_bool_throws_on_array(): void
    {
        $this->expectException(BadRequestException::class);

        $this->body(['flag' => ['1']])->bool('flag');
    }

    public function test_array_reads_an_array_field(): void
    {
        $this->assertSame(['a', 'b'], $this->body(['tags' => ['a', 'b']])->array('tags'));
        $this->assertSame([], $this->body(['tags' => []])->array('tags', ['fallback']));
    }

    public function test_array_defaults_when_absent(): void
    {
        $this->assertSame([], $this->body([])->array('tags'));
        $this->assertSame(['x'], $this->body([])->array('tags', ['x']));
    }

    public function test_array_throws_on_scalar(): void
    {
        // A scalar where an array was expected is malformed input, not a
        // one-element array.
        $this->expectException(BadRequestException::class);

        $this->body(['tags' => 'oops'])->array('tags');
    }

    public function test_has_distinguishes_present_empty_from_absent(): void
    {
        $input = $this->body(['name' => '']);

        $this->assertTrue($input->has('name'));
        $this->assertFalse($input->has('missing'));
    }

    public function test_handles_null_parsed_body(): void
    {
        $input = $this->body(null);

        $this->assertFalse($input->has('name'));
        $this->assertSame('', $input->string('name'));
    }

    public function test_handles_object_parsed_body(): void
    {
        // PSR-7 getParsedBody() may return an object; (array) casts public
        // props to keys, so a stdClass body reads back by field name.
        $input = $this->body((object) ['name' => 'Ada', 'age' => '42']);

        $this->assertTrue($input->has('name'));
        $this->assertSame('Ada', $input->string('name'));
        $this->assertSame(42, $input->int('age'));
    }
}
