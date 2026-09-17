<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Admin\Abilities;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\Contracts\AbilityInterface;
use Hydra\Tests\Fixture\Entities\User;

/**
 * The ability a module declares to keep a signed-in non-admin out.
 *
 * The backend itself is gated on being signed in, not on holding a role, so
 * this is what separates the two: a module that names it is admin-only, and a
 * module that names nothing is open to anyone with an account.
 */
final class AccessAdmin implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user instanceof User && $user->isAdmin();
    }
}
