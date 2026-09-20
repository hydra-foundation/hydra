<?php

declare(strict_types=1);

namespace Hydra\Validation\Contracts;

use Hydra\Validation\Context;

/**
 * A single validation rule.
 */
interface RuleInterface
{
    /**
     * The failure message, or null when the value passes. The context carries
     * the rest of the input, for the rules that compare one field to another.
     */
    public function validate(mixed $value, Context $context): ?string;
}
