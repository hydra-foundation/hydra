<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Contracts\RuleInterface;

/**
 * Required
 *
 * The value must be present: not null, not an empty string, not an empty array.
 */
final class Required implements RuleInterface
{
    public function __construct(private readonly string $message = 'This field is required.') {}

    public function validate(mixed $value): ?string
    {
        $missing = $value === null
            || $value === ''
            || (is_array($value) && $value === []);

        return $missing ? $this->message : null;
    }
}
