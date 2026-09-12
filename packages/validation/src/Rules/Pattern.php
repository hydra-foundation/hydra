<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value, as a string, must match a PCRE pattern.
 */
final class Pattern implements RuleInterface
{
    use TextualValue;

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
}
