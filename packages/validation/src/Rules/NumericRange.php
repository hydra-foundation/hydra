<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be a number within $min..$max, both inclusive. The fractional
 * counterpart to {@see Range}: a price, a rating, a weight.
 */
final class NumericRange implements RuleInterface
{
    use NumericValue;

    private readonly string $message;

    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
        ?string $message = null,
    ) {
        if ($min > $max) {
            throw new InvalidArgumentException("NumericRange lower bound {$min} is above its upper bound {$max}.");
        }

        $this->message = $message ?? "Must be a number between {$min} and {$max}.";
    }

    public function validate(mixed $value, Context $context): ?string
    {
        $number = $this->toNumber($value);

        if ($number === null) {
            return $this->message;
        }

        return $number < $this->min || $number > $this->max ? $this->message : null;
    }
}
