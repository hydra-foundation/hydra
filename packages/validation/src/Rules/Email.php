<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be an email address. Syntax only — whether the mailbox exists
 * is answered by sending to it, which is what a verification link is for.
 */
final class Email implements RuleInterface
{
    /** The longest address a path can carry, from RFC 5321. */
    private const MAX_LENGTH = 254;

    public function __construct(
        private readonly string $message = 'Enter a valid email address.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_LENGTH) {
            return $this->message;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? $this->message : null;
    }
}
