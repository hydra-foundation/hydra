<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Rules\Email;
use Hydra\Validation\Testing\RuleContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A rule that reads a string and is routinely handed something else.
 */
#[CoversClass(Email::class)]
final class EmailContractTest extends RuleContractTestCase
{
    protected function rule(): RuleInterface
    {
        return new Email;
    }

    public static function passingValues(): iterable
    {
        yield 'an address' => ['ada@example.com'];
        yield 'a subdomain' => ['ada@mail.example.com'];
    }

    public static function failingValues(): iterable
    {
        yield 'no at sign' => ['ada.example.com'];
        yield 'no domain' => ['ada@'];
    }
}
