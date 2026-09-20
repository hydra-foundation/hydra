<?php

declare(strict_types=1);

namespace Hydra\Validation\Rules;

use Hydra\Validation\Context;
use Hydra\Validation\Contracts\RuleInterface;
use InvalidArgumentException;

/**
 * Required as soon as any of the named sibling fields was supplied. A shipping
 * address is the case: blank is fine, half filled in is not.
 */
final class RequiredWith implements RuleInterface
{
    use PresentValue;

    /** @var list<string> */
    private readonly array $fields;

    /** @param list<string> $fields */
    public function __construct(
        array $fields,
        private readonly string $message = 'This field is required.',
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('RequiredWith needs at least one field to depend on.');
        }

        $this->fields = array_values($fields);
    }

    public function validate(mixed $value, Context $context): ?string
    {
        foreach ($this->fields as $field) {
            if (!$this->isMissing($context->sibling($field))) {
                return $this->isMissing($value) ? $this->message : null;
            }
        }

        return null;
    }
}
