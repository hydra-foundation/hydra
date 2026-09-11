<?php

declare(strict_types=1);

namespace Hydra\Authorization\Contracts;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * Ability interface
 *
 * A single authorization rule: may this user do this thing, to this subject?
 */
interface AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool;
}
