<?php

declare(strict_types=1);

namespace Hydra\Validation\Contracts;

use Hydra\Validation\Context;

/**
 * A rule that can end its field's chain early, leaving the field valid. This
 * is how "optional" is expressed: the rules after it never run.
 */
interface ShortCircuitInterface
{
    public function shortCircuits(mixed $value, Context $context): bool;
}
