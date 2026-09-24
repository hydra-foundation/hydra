<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A code or recovery code for a pending sign-in was wrong, or already used
 */
final class TwoFactorFailed
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
