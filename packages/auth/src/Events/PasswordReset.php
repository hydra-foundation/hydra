<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * {@see $user} set a new password through a reset link
 */
final class PasswordReset
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
