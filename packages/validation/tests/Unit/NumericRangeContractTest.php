<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\NumericRange;
use Hydra\Validation\Testing\RuleContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A rule that coerces before it compares, which is where odd types bite.
 */
#[CoversClass(NumericRange::class)]
final class NumericRangeContractTest extends RuleContractTestCase
{
    protected function rule(): RuleInterface
    {
        return new NumericRange(1, 10);
    }

    public static function passingValues(): iterable
    {
        yield 'inside' => [5];
        yield 'at the floor' => [1];
        yield 'at the ceiling' => [10];
        yield 'a numeric string' => ['5'];
    }

    public static function failingValues(): iterable
    {
        yield 'below' => [0];
        yield 'above' => [11];
    }
}
