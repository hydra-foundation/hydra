<?php

declare(strict_types=1);

namespace Hydra\Validation\Contracts;

/**
 * A single validation rule.
 */
interface RuleInterface
{
    /** The failure message, or null when the value passes. */
    public function validate(mixed $value): ?string;
}
