<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a whole number, unbounded.
 */
final class Integer implements RuleInterface
{
    use WholeNumber;

    public function __construct(
        private readonly string $message = 'Must be a whole number.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        return $this->toInt($value) === null ? $this->message : null;
    }
}
