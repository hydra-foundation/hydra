<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be ASCII letters and digits, optionally with underscores and
 * hyphens. A username, a coupon code, a room name.
 */
final class AlphaNumeric implements RuleInterface
{
    private readonly string $pattern;

    public function __construct(
        bool $allowDashes = false,
        private readonly string $message = 'Use letters and numbers only.',
    ) {
        $this->pattern = $allowDashes ? '/^[A-Za-z0-9_-]+$/' : '/^[A-Za-z0-9]+$/';
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }

        return preg_match($this->pattern, $value) === 1 ? null : $this->message;
    }
}
