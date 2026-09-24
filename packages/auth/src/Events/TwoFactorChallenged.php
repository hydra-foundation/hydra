<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * The password was right and the account has a second factor: a code is now asked for
 */
final class TwoFactorChallenged
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
