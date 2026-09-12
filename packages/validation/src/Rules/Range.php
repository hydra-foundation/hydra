<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a whole number within $min..$max (both inclusive). Numbers
 * that arrive from a query string are strings, and a string that is not
 * a number is out of range rather than zero. Coercing "abc" to 0 would quietly
 * pass a rule whose whole job is to bound what reaches a query.
 */
final class Range implements RuleInterface
{
    private readonly string $message;

    public function __construct(
        private readonly int $min,
        private readonly int $max,
        ?string $message = null,
    ) {
        $this->message = $message ?? "Must be a whole number between {$min} and {$max}.";
    }

    public function validate(mixed $value): ?string
    {
        $number = $this->toInt($value);

        if ($number === null) {
            return $this->message;
        }

        return $number < $this->min || $number > $this->max ? $this->message : null;
    }

    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        // Only a string of digits counts. Floats are excluded along with
        // everything else: "2.5" is not a whole number, and silently flooring
        // it would accept a value the caller never sent.
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
