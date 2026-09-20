<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * Required when another field holds one of the given values, compared on the
 * string form so a select's "other" reads the same whether it arrived from a
 * form or from JSON.
 */
final class RequiredIf implements RuleInterface
{
    use PresentValue;

    /** @var list<string> */
    private readonly array $values;

    /** @param list<string|int|bool> $values */
    public function __construct(
        private readonly string $field,
        array $values,
        private readonly string $message = 'This field is required.',
    ) {
        if ($values === []) {
            throw new InvalidArgumentException('RequiredIf needs at least one value to match.');
        }

        $this->values = array_values(array_map(
            static fn (string|int|bool $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            $values,
        ));
    }

    public function validate(mixed $value, Context $context): ?string
    {
        $other = $context->sibling($this->field);

        if (is_bool($other)) {
            $other = $other ? '1' : '0';
        }

        if (!is_scalar($other) || !in_array((string) $other, $this->values, true)) {
            return null;
        }

        return $this->isMissing($value) ? $this->message : null;
    }
}
