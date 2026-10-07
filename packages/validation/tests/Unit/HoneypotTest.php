<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Rules\Honeypot;
use Hydra\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Honeypot::class)]
final class HoneypotTest extends TestCase
{
    public function test_an_absent_or_empty_trap_passes(): void
    {
        $rules = ['website' => [new Honeypot]];
        $validator = new Validator;

        $this->assertTrue($validator->validate([], $rules)->passes());
        $this->assertTrue($validator->validate(['website' => null], $rules)->passes());
        $this->assertTrue($validator->validate(['website' => ''], $rules)->passes());
    }

    /** @return iterable<string, array{mixed}> */
    public static function filled(): iterable
    {
        yield 'a space' => [' '];
        yield 'text' => ['https://spam.example'];
        yield 'zero' => ['0'];
        yield 'an int' => [0];
        yield 'an array' => [['x']];
        yield 'an empty array' => [[]];
    }

    #[DataProvider('filled')]
    public function test_anything_in_the_trap_fails(mixed $value): void
    {
        $result = (new Validator)->validate(['website' => $value], ['website' => [new Honeypot]]);

        $this->assertSame('Your message could not be sent.', $result->first('website'));
    }

    public function test_the_message_can_be_replaced(): void
    {
        $result = (new Validator)->validate(['website' => 'x'], ['website' => [new Honeypot('Nope.')]]);

        $this->assertSame('Nope.', $result->first('website'));
    }
}
