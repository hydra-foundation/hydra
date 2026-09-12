<?php

declare(strict_types=1);

namespace Hydra\Validation;

/**
 * The outcome of validating a set of fields.
 */
final readonly class Result
{
    /**
     * @param array<string, string> $errors one message per failed field
     * @param array<string, mixed> $validated
     */
    public function __construct(
        private array $errors = [],
        private array $validated = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * The vetted subset of the input. Exactly the fields that were listed in
     * the rules AND present in the input, nothing else. Consume this instead
     * of reaching back into the raw request:
     */
    /** @return array<string, mixed> */
    public function validated(): array
    {
        if ($this->errors !== []) {
            throw new \LogicException(
                'Cannot read validated data from a failed validation result; check passes() first.',
            );
        }

        return $this->validated;
    }
}
