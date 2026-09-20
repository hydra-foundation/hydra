<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a URL slug: lowercase letters and digits in groups
 * separated by single hyphens, with no hyphen at either end.
 */
final class Slug implements RuleInterface
{
    public function __construct(
        private readonly string $message = 'Use lowercase letters, numbers and hyphens.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 ? null : $this->message;
    }
}
