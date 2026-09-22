<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Slug;
use Hydra\Validation\Testing\RuleContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A rule that matches a pattern — the shape most application rules take.
 */
#[CoversClass(Slug::class)]
final class SlugContractTest extends RuleContractTestCase
{
    protected function rule(): RuleInterface
    {
        return new Slug;
    }

    public static function passingValues(): iterable
    {
        yield 'a slug' => ['hello-world'];
        yield 'digits' => ['post-2026'];
    }

    public static function failingValues(): iterable
    {
        yield 'spaces' => ['hello world'];
        yield 'uppercase' => ['Hello-World'];
    }
}
