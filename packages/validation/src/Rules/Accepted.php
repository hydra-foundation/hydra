<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must read as true. Terms of service, an age gate, a consent box:
 * unticked, the control is absent from the submission entirely, which fails.
 */
final class Accepted implements RuleInterface
{
    private const TRUTHY = ['1', 'true', 'on', 'yes'];

    public function __construct(
        private readonly string $message = 'This must be accepted.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if ($value === true) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            return $this->message;
        }

        return in_array(strtolower(trim((string) $value)), self::TRUTHY, true) ? null : $this->message;
    }
}
