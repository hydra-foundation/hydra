<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

/**
 * Shared by every rule that asks whether a value was supplied, so "missing"
 * means the same thing to all of them.
 */
trait PresentValue
{
    private function isMissing(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || (is_array($value) && $value === []);
    }
}
