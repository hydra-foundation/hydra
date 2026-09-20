<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be an array holding between $min and $max entries inclusive,
 * with no upper bound when $max is null.
 */
final class CountRange implements RuleInterface
{
    private readonly string $message;

    public function __construct(
        private readonly int $min,
        private readonly ?int $max = null,
        ?string $message = null,
    ) {
        if ($min < 0) {
            throw new InvalidArgumentException("CountRange lower bound {$min} is negative.");
        }

        if ($max !== null && $max < $min) {
            throw new InvalidArgumentException("CountRange lower bound {$min} is above its upper bound {$max}.");
        }

        $this->message = $message ?? ($max === null
            ? "Must have at least {$min} entries."
            : "Must have between {$min} and {$max} entries.");
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_array($value)) {
            return $this->message;
        }

        $count = count($value);

        return $count < $this->min || ($this->max !== null && $count > $this->max)
            ? $this->message
            : null;
    }
}
