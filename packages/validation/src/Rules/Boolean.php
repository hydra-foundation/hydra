<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must read as true or false. A checkbox submits "on", a JSON client
 * sends a real bool, and a hidden field sends "0"; all three are a boolean.
 */
final class Boolean implements RuleInterface
{
    private const TRUTHY = ['1', 'true', 'on', 'yes'];
    private const FALSY = ['0', 'false', 'off', 'no', ''];

    public function __construct(
        private readonly string $message = 'Must be true or false.',
    ) {}

    public function validate(mixed $value, Context $context): ?string
    {
        if (is_bool($value)) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            return $this->message;
        }

        $normalised = strtolower(trim((string) $value));

        return in_array($normalised, self::TRUTHY, true) || in_array($normalised, self::FALSY, true)
            ? null
            : $this->message;
    }
}
