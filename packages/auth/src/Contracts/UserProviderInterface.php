<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * Where users come from (fulfilled by the application)
 */
interface UserProviderInterface
{
    /**
     * Find the user with this identifier, or null if none. Used to restore the
     * authenticated user from the id the guard kept in the session.
     */
    public function byIdentifier(int|string $id): ?AuthenticatableInterface;

    /**
     * Find the user with this username (or whatever single field a login is
     * keyed on — an email, say), or null if none. The guard then verifies the
     * submitted password against the returned user's stored hash; this method
     * itself performs no password check.
     */
    public function byUsername(string $username): ?AuthenticatableInterface;
}
