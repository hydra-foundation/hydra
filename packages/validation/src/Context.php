<?php

declare(strict_types=1);

namespace Hydra\Validation;

/**
 * What a rule can see beyond its own value: the whole input, and where in it
 * the value being checked came from.
 */
final readonly class Context
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private array $data,
        private string $field,
    ) {}

    /** The concrete path under validation, wildcards already resolved. */
    public function field(): string
    {
        return $this->field;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** A dot path read from the root of the input. */
    public function value(string $path): mixed
    {
        return Path::get($this->data, $path);
    }

    public function has(string $path): bool
    {
        return Path::has($this->data, $path);
    }

    /**
     * A key read from the same scope as the field under validation, so a rule
     * naming 'password' from 'password_confirmation' resolves at the top level
     * and the same rule under 'users.2.password_confirmation' resolves within
     * that row.
     */
    public function sibling(string $key): mixed
    {
        return Path::get($this->data, $this->siblingPath($key));
    }

    public function hasSibling(string $key): bool
    {
        return Path::has($this->data, $this->siblingPath($key));
    }

    public function siblingPath(string $key): string
    {
        $separator = strrpos($this->field, '.');

        return $separator === false ? $key : substr($this->field, 0, $separator) . '.' . $key;
    }
}
