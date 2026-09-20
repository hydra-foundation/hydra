<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a whole number within $min..$max, both inclusive.
 */
final class Range implements RuleInterface
{
    use WholeNumber;

    private readonly string $message;

    public function __construct(
        private readonly int $min,
        private readonly int $max,
        ?string $message = null,
    ) {
        $this->message = $message ?? "Must be a whole number between {$min} and {$max}.";
    }

    public function validate(mixed $value, Context $context): ?string
    {
        $number = $this->toInt($value);

        if ($number === null) {
            return $this->message;
        }

        return $number < $this->min || $number > $this->max ? $this->message : null;
    }
}
