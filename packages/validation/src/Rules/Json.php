<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be a string holding well-formed JSON.
 */
final class Json implements RuleInterface
{
    public function __construct(
        private readonly int $maxDepth = 512,
        private readonly string $message = 'Must be valid JSON.',
    ) {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException("Json depth must be at least 1, not {$maxDepth}.");
        }
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return $this->message;
        }

        json_decode($value, true, $this->maxDepth);

        return json_last_error() === JSON_ERROR_NONE ? null : $this->message;
    }
}
