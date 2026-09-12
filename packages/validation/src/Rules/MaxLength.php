<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;
use Stringable;

/**
 * The value, as a string, must be at most $max characters long (multibyte-aware).
 */
final class MaxLength implements RuleInterface
{
    private readonly string $message;

    public function __construct(private readonly int $max, ?string $message = null)
    {
        $this->message = $message ?? "Must be {$max} characters or fewer.";
    }

    public function validate(mixed $value): ?string
    {
        if (!$this->isTextual($value)) {
            return $this->message;
        }

        return mb_strlen((string) $value) > $this->max ? $this->message : null;
    }

    /**
     * Whether the value can be safely treated as text. Booleans are excluded
     * deliberately: real text input never arrives as a bool, and "1"/"" from
     * a cast would be misleading lengths rather than what the client sent.
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
