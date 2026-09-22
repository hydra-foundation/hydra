<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Testing\RuleContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A rule that counts, where the boundary is the whole question.
 */
#[CoversClass(MaxLength::class)]
final class MaxLengthContractTest extends RuleContractTestCase
{
    protected function rule(): RuleInterface
    {
        return new MaxLength(5);
    }

    public static function passingValues(): iterable
    {
        yield 'shorter' => ['abc'];
        yield 'exactly the limit' => ['abcde'];
    }

    public static function failingValues(): iterable
    {
        yield 'one over' => ['abcdef'];
    }
}
