<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * A rule written inline. The check returns true when the value is acceptable;
 * anything needing a name, a constructor or a test of its own should be a
 * class implementing {@see RuleInterface} instead.
 */
final class Callback implements RuleInterface
{
    /** @var callable(mixed, Context): bool */
    private $check;

    /** @param callable(mixed, Context): bool $check */
    public function __construct(
        callable $check,
        private readonly string $message = 'This value is not allowed.',
    ) {
        $this->check = $check;
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return ($this->check)($value, $context) ? null : $this->message;
    }
}
