<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use Hydra\Validation\Contracts\ShortCircuitInterface;

/**
 * Validate the rest of the chain only when something was supplied. An empty
 * string counts as nothing, because that is what an untouched text input
 * submits; put this first, and a length rule on an optional field means "if
 * you fill it in, make it long enough".
 */
final class Nullable implements RuleInterface, ShortCircuitInterface
{
    use PresentValue;

    public function shortCircuits(mixed $value, Context $context): bool
    {
        return $this->isMissing($value);
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return null;
    }
}
