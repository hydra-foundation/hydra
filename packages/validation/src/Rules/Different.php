<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must not be identical to another field in the same scope. A new
 * password that matches the old one, a recipient who is the sender.
 */
final class Different implements RuleInterface
{
    private readonly string $message;

    public function __construct(
        private readonly string $field,
        ?string $message = null,
    ) {
        $this->message = $message ?? "Must be different from {$field}.";
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return $value === $context->sibling($this->field) ? $this->message : null;
    }
}
