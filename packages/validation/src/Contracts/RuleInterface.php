<?php

declare(strict_types=1);

namespace Hydra\Validation\Contracts;

/**
 * A single validation rule
 */
interface RuleInterface
{
    public function validate(mixed $value): ?string;
}
