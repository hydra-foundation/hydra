<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\BadRequestException;
use Hydra\Http\Input;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    private function input(array|object|null $body): Input
    {
        $request = (new Psr17Factory)->createServerRequest('POST', '/')->withParsedBody($body);

        return Input::fromRequest($request);
    }

    public function testStringReadsAField(): void
    {
        $this->assertSame('Ada', $this->input(['name' => 'Ada'])->string('name'));
    }

    public function testStringFallsBackToDefaultWhenAbsent(): void
    {
        $input = $this->input(['name' => 'Ada']);

        $this->assertSame('', $input->string('missing'));
        $this->assertSame('anon', $input->string('missing', 'anon'));
    }

    public function testStringDoesNotTrim(): void
    {
        // Trimming is the caller's choice; the reader returns the value as sent.
        $this->assertSame('  spaced  ', $this->input(['x' => '  spaced  '])->string('x'));
    }

    public function testStringKeepsFalsyZero(): void
    {
        $this->assertSame('0', $this->input(['x' => '0'])->string('x'));
    }

    public function testStringDefaultsWhenFieldIsAnArray(): void
    {
        // name[] arrives as an array; stringifying it would be a TypeError.
        $this->assertSame('', $this->input(['name' => ['a', 'b']])->string('name'));
    }

    public function testIntCoercesNumericStrings(): void
    {
        $this->assertSame(42, $this->input(['age' => '42'])->int('age'));
        $this->assertSame(0, $this->input(['age' => '0'])->int('age'));
    }

    public function testIntDefaultsOnNonNumeric(): void
    {
        $this->assertNull($this->input(['age' => 'old'])->int('age'));
        $this->assertSame(-1, $this->input([])->int('age', -1));
        $this->assertNull($this->input(['age' => ''])->int('age'));
    }

    public function testFloatCoercesNumericStrings(): void
    {
        $this->assertSame(3.14, $this->input(['price' => '3.14'])->float('price'));
        $this->assertSame(42.0, $this->input(['price' => '42'])->float('price'));
        $this->assertSame(0.0, $this->input(['price' => '0'])->float('price'));
    }

    public function testFloatReadsARealFloatFromAJsonBody(): void
    {
        $this->assertSame(1.5, $this->input(['price' => 1.5])->float('price'));
    }

    public function testFloatDefaultsOnMissAndNonNumeric(): void
    {
        $this->assertNull($this->input([])->float('price'));
        $this->assertSame(9.99, $this->input([])->float('price', 9.99));
        $this->assertNull($this->input(['price' => 'cheap'])->float('price'));
        $this->assertNull($this->input(['price' => ''])->float('price'));
        $this->assertNull($this->input(['price' => ['1.5']])->float('price'));
    }

    public function testBoolAcceptsExplicitTrueForms(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', 'On', 1, true] as $form) {
            $this->assertTrue($this->input(['flag' => $form])->bool('flag'), var_export($form, true));
        }
    }

    public function testBoolAcceptsExplicitFalseForms(): void
    {
        foreach (['false', 'FALSE', '0', 'no', 'off', 'Off', 0, false] as $form) {
            $this->assertFalse($this->input(['flag' => $form])->bool('flag'), var_export($form, true));
        }
    }

    public function testBoolDefaultsWhenAbsent(): void
    {
        $this->assertNull($this->input([])->bool('flag'));
        $this->assertTrue($this->input([])->bool('flag', true));
        $this->assertFalse($this->input([])->bool('flag', false));
    }

    public function testBoolThrowsOnGarbage(): void
    {
        $this->expectException(BadRequestException::class);

        $this->input(['flag' => 'maybe'])->bool('flag');
    }

    public function testBoolThrowsOnEmptyString(): void
    {
        // "" is present but is neither an explicit true nor false form.
        $this->expectException(BadRequestException::class);

        $this->input(['flag' => ''])->bool('flag');
    }

    public function testBoolThrowsOnArray(): void
    {
        $this->expectException(BadRequestException::class);

        $this->input(['flag' => ['1']])->bool('flag');
    }

    public function testArrayReadsAnArrayField(): void
    {
        $this->assertSame(['a', 'b'], $this->input(['tags' => ['a', 'b']])->array('tags'));
        $this->assertSame([], $this->input(['tags' => []])->array('tags', ['fallback']));
    }

    public function testArrayDefaultsWhenAbsent(): void
    {
        $this->assertSame([], $this->input([])->array('tags'));
        $this->assertSame(['x'], $this->input([])->array('tags', ['x']));
    }

    public function testArrayThrowsOnScalar(): void
    {
        // A scalar where an array was expected is malformed input, not a
        // one-element array.
        $this->expectException(BadRequestException::class);

        $this->input(['tags' => 'oops'])->array('tags');
    }

    public function testHasDistinguishesPresentEmptyFromAbsent(): void
    {
        $input = $this->input(['name' => '']);

        $this->assertTrue($input->has('name'));
        $this->assertFalse($input->has('missing'));
    }

    public function testHandlesNullParsedBody(): void
    {
        $input = $this->input(null);

        $this->assertFalse($input->has('name'));
        $this->assertSame('', $input->string('name'));
    }

    public function testHandlesObjectParsedBody(): void
    {
        // PSR-7 getParsedBody() may return an object; (array) casts public
        // props to keys, so a stdClass body reads back by field name.
        $input = $this->input((object) ['name' => 'Ada', 'age' => '42']);

        $this->assertTrue($input->has('name'));
        $this->assertSame('Ada', $input->string('name'));
        $this->assertSame(42, $input->int('age'));
    }
}
