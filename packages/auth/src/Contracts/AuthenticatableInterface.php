<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * The two things auth needs from a user object, so an application's own model
 * can play the part without inheriting anything from the framework.
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
     * usable password, which {@see HasherInterface::verify()} treats as never
     * matching.
     */
    public function getAuthPassword(): string;
}
