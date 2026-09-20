<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be present: not null, not an empty string, not an empty array.
 */
final class Required implements RuleInterface
{
    use PresentValue;

    public function __construct(private readonly string $message = 'This field is required.') {}

    public function validate(mixed $value, Context $context): ?string
    {
        return $this->isMissing($value) ? $this->message : null;
    }
}
