<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Testing\RuleContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The rule whose whole job is the absent value, so its passing cases are the
 * falsy ones that are nonetheless answers: 0 and false are values a form sent.
 */
#[CoversClass(Required::class)]
final class RequiredContractTest extends RuleContractTestCase
{
    protected function rule(): RuleInterface
    {
        return new Required;
    }

    public static function passingValues(): iterable
    {
        yield 'a string' => ['something'];
        yield 'zero, which is a value' => [0];
        yield 'false, which is also a value' => [false];
        // Surprising, and deliberate: Required asks whether a value is present,
        // not whether it means anything. Trimming is the caller's, which is why
        // a controller trims its input before it validates it.
        yield 'whitespace only' => ['   '];
    }

    public static function failingValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }
}
