<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Pattern
 *
 * The value, as a string, must match a PCRE pattern.
 */
final class Pattern implements RuleInterface
{
    public function __construct(
        private readonly string $pattern,
        private readonly string $message = 'This value is not in the expected format.',
    ) {
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Invalid validation pattern: {$pattern}");
        }
    }

    public function validate(mixed $value): ?string
    {
        if (!$this->isTextual($value)) {
            return $this->message;
        }

        return preg_match($this->pattern, (string) $value) === 1 ? null : $this->message;
    }

    /**
     * Whether the value can be safely treated as text. Booleans are excluded
     * deliberately: real text input never arrives as a bool, and matching a
     * pattern against "1"/"" would test a cast artifact, not client input.
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
