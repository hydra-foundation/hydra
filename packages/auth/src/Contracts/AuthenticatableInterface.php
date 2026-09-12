<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * What a user must expose for authentication
 */
interface AuthenticatableInterface
{
    /**
     * The value the guard stores in the session and later restores the user by
     * (typically the primary key). Must be stable for the life of the account.
     */
    public function getAuthIdentifier(): int|string;

    /**
     * The stored password hash. May be an empty string for an account with no
     * usable password — {@see HasherInterface::verify()} treats that as never
     * matching.
     */
    public function getAuthPassword(): string;
}
