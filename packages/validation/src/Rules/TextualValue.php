<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Stringable;

/**
 * Shared by every rule that measures or matches a value as text, so the set of
 * types that counts as text is decided in one place.
 */
trait TextualValue
{
    /**
     * Booleans are excluded deliberately: a bool casts to "1" or "", which a
     * length or pattern rule would then measure as a cast artifact rather than
     * as what the client actually sent.
     */
    private function isTextual(mixed $value): bool
    {
        return $value === null
            || is_string($value)
            || is_int($value)
            || is_float($value)
            || $value instanceof Stringable;
    }
}
