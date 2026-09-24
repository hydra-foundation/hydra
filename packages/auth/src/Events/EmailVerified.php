<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * {@see $user} confirmed the address the account holds
 */
final class EmailVerified
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
