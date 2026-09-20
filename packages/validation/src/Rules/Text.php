<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a string. A JSON body can carry an array or an object
 * where a column expects text, and the length rules measure it happily.
 */
final class Text implements RuleInterface
{
    public function __construct(
        private readonly string $message = 'Must be text.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        return is_string($value) ? null : $this->message;
    }
}
