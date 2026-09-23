<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * Lookup by email address, kept apart from {@see UserProviderInterface} so an
 * application without password reset never has to implement it.
 */
interface EmailUserProviderInterface
{
    /** Find the user with this address, or null if none. */
    public function byEmail(string $email): ?AuthenticatableInterface;
}
