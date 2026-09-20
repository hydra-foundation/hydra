<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

/**
 * Shared by the rules that read a value as a number, whole or not. NAN and INF
 * are refused: both survive a numeric check and then poison every comparison
 * downstream.
 */
trait NumericValue
{
    private function toNumber(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $number = $trimmed + 0;

        return is_float($number) && !is_finite($number) ? null : $number;
    }
}
