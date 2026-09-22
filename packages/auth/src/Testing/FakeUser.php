<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A user that is an identifier and a stored hash and nothing else, for a test
 * that needs somebody signed in and does not care who beyond that.
 */
final class FakeUser implements AuthenticatableInterface
{
    /** @param string $password the stored hash; empty is the documented "no usable password" */
    public function __construct(
        private readonly int|string $id = 1,
        private readonly string $password = '',
    ) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}
