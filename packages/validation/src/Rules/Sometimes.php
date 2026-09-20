<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Contracts\ShortCircuitInterface;

/**
 * Validate the rest of the chain only when the field is in the input at all.
 * A PATCH that sends three of eight columns is the case: the five it omits are
 * not being changed, and must not be judged as blank.
 */
final class Sometimes implements RuleInterface, ShortCircuitInterface
{
    public function shortCircuits(mixed $value, Context $context): bool
    {
        return !$context->has($context->field());
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return null;
    }
}
