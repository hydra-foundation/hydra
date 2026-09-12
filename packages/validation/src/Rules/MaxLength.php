<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value, as a string, must be at most $max characters long (multibyte-aware).
 */
final class MaxLength implements RuleInterface
{
    use TextualValue;

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
}
