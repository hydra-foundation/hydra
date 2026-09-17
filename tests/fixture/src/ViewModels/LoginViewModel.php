<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\ViewModels;

/**
 * The login form's state across a failed attempt: what was typed, and why it
 * was refused.
 */
final readonly class LoginViewModel
{
    /** @param array<string, string> $errors */
    public function __construct(
        public string $username = '',
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasError(string $name): bool
    {
        return array_key_exists($name, $this->errors);
    }

    public function error(string $name): string
    {
        return $this->errors[$name] ?? '';
    }

    /**
     * The errors that belong to no field, which are the ones a form shows at
     * the top. A failed login is deliberately one of these: naming the field
     * that was wrong is what tells a guesser which half to keep.
     *
     * @return list<string>
     */
    public function formErrors(): array
    {
        return array_values(array_diff_key($this->errors, array_flip(['username', 'password'])));
    }
}
