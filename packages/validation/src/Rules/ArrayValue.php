<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be an array. The rule to put on the container a `*` pattern
 * walks, so a missing basket is one clear error rather than no errors at all.
 */
final class ArrayValue implements RuleInterface
{
    public function __construct(
        private readonly string $message = 'Must be a list of values.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        return is_array($value) ? null : $this->message;
    }
}
