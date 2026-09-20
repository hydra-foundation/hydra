<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must equal the companion field named after it, by convention
 * "password" and "password_confirmation". Put it on the field itself, not on
 * the confirmation, so the error lands under the input the person retypes.
 */
final class Confirmed implements RuleInterface
{
    public function __construct(
        private readonly string $suffix = '_confirmation',
        private readonly string $message = 'The confirmation does not match.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        $field = $context->field();
        $separator = strrpos($field, '.');
        $name = $separator === false ? $field : substr($field, $separator + 1);

        return $value === $context->sibling($name . $this->suffix) ? null : $this->message;
    }
}
