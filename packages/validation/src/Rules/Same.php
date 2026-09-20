<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be identical to another field in the same scope. Identical,
 * not equal: "1" does not match 1.
 */
final class Same implements RuleInterface
{
    private readonly string $message;

    public function __construct(
        private readonly string $field,
        ?string $message = null,
    ) {
        $this->message = $message ?? "Must match {$field}.";
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return $value === $context->sibling($this->field) ? null : $this->message;
    }
}
