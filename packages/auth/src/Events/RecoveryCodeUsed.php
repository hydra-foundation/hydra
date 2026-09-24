<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A recovery code stood in for the authenticator; $remaining is how many are left
 */
final class RecoveryCodeUsed
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
        public readonly int $remaining,
    ) {}
}
