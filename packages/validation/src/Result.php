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
     * Exactly the fields the rules named and the input carried, nothing else.
     * Read this rather than the raw request, so a field nobody validated
     * cannot reach the code downstream.
     *
     * @return array<string, mixed>
     */
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
