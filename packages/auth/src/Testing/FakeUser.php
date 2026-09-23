<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\HasEmailInterface;

/**
 * A user that is an identifier, a stored hash and an address and nothing else,
 * for a test that needs somebody signed in and does not care who beyond that.
 */
final class FakeUser implements HasEmailInterface
{
    /** @param string $password the stored hash; empty is the documented "no usable password" */
    public function __construct(
        private readonly int|string $id = 1,
        private readonly string $password = '',
        private readonly string $email = 'ada@example.com',
    ) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function getAuthEmail(): string
    {
        return $this->email;
    }
}
