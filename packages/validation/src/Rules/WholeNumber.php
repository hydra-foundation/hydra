<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

/**
 * Shared by the rules that read a value as a whole number. Numbers arriving
 * from a query string or a form are strings, and a string that is not a number
 * is not zero: coercing "abc" to 0 would quietly pass a rule whose whole job is
 * to bound what reaches a query. Floats are excluded for the same reason —
 * "2.5" is not a whole number, and flooring it accepts what nobody sent.
 */
trait WholeNumber
{
    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
