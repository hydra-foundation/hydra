<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;

/**
 * The value must be a date written exactly in $format. Exactly, because PHP's
 * parser is forgiving enough to read 2026-02-31 as the 3rd of March, and a
 * date rule that accepts a day the month does not have is not a rule.
 */
final class Date implements RuleInterface
{
    use ParsesDates;

    private readonly string $message;

    public function __construct(
        private readonly string $format = 'Y-m-d',
        ?string $message = null,
    ) {
        $this->message = $message ?? "Enter a date in the format {$format}.";
    }

    public function validate(mixed $value, Context $context): ?string
    {
        return $this->parse($value, $this->format) === null ? $this->message : null;
    }
}
