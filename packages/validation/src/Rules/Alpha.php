<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be ASCII letters only. Deliberately ASCII: this rule belongs
 * on a code or a key, not on anything a person writes their own name into.
 */
final class Alpha implements RuleInterface
{
    public function __construct(
        private readonly string $message = 'Use letters only.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }

        return preg_match('/^[A-Za-z]+$/', $value) === 1 ? null : $this->message;
    }
}
