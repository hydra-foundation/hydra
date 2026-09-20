<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use DateTimeImmutable;
use Exception;
use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * The value must be a date in $format falling within the bounds, each of them
 * inclusive and either of them optional. The bounds are read by
 * DateTimeImmutable, so 'today', '-18 years' and '2026-01-01' all work, and
 * they are resolved once when the rule is built.
 */
final class DateRange implements RuleInterface
{
    use ParsesDates;

    private readonly ?DateTimeImmutable $lower;
    private readonly ?DateTimeImmutable $upper;
    private readonly string $message;

    public function __construct(
        ?string $min = null,
        ?string $max = null,
        private readonly string $format = 'Y-m-d',
        ?string $message = null,
    ) {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('DateRange needs at least one bound.');
        }

        $this->lower = $this->bound($min);
        $this->upper = $this->bound($max);

        if ($this->lower !== null && $this->upper !== null && $this->lower > $this->upper) {
            throw new InvalidArgumentException("DateRange lower bound {$min} is after its upper bound {$max}.");
        }

        $this->message = $message ?? match (true) {
            $min === null => "Enter a date no later than {$max}.",
            $max === null => "Enter a date no earlier than {$min}.",
            default => "Enter a date between {$min} and {$max}.",
        };
    }

    public function validate(mixed $value, Context $context): ?string
    {
        $date = $this->parse($value, $this->format);

        if ($date === null) {
            return $this->message;
        }

        if ($this->lower !== null && $date < $this->lower) {
            return $this->message;
        }

        return $this->upper !== null && $date > $this->upper ? $this->message : null;
    }

    private function bound(?string $bound): ?DateTimeImmutable
    {
        if ($bound === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($bound);
        } catch (Exception $e) {
            throw new InvalidArgumentException("DateRange cannot read the bound {$bound}.", 0, $e);
        }
    }
}
