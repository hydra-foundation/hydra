<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * A field no person sees, so any value in it came from a bot that fills
 * every input it finds. Absent and empty both pass: a form that forgot to
 * print the trap must not lock out the people using it.
 */
final class Honeypot implements RuleInterface
{
    public function __construct(
        private readonly string $message = 'Your message could not be sent.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        return $value === null || $value === '' ? null : $this->message;
    }
}
