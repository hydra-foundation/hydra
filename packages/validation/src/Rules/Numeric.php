<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a number, whole or fractional.
 */
final class Numeric implements RuleInterface
{
    use NumericValue;

    public function __construct(
        private readonly string $message = 'Must be a number.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        return $this->toNumber($value) === null ? $this->message : null;
    }
}
