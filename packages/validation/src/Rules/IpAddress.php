<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be an IP address, of either family or of the one named.
 */
final class IpAddress implements RuleInterface
{
    private readonly int $flags;

    public function __construct(
        private readonly ?int $version = null,
        private readonly string $message = 'Enter a valid IP address.',
    ) {
        $this->flags = match ($version) {
            4 => FILTER_FLAG_IPV4,
            6 => FILTER_FLAG_IPV6,
            null => 0,
            default => throw new InvalidArgumentException("IpAddress knows version 4 and 6, not {$version}."),
        };
    }

    public function validate(mixed $value, Context $context): ?string
    {
        if (!is_string($value)) {
            return $this->message;
        }

        return filter_var($value, FILTER_VALIDATE_IP, $this->flags) === false ? $this->message : null;
    }
}
