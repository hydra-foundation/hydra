<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A user was authenticated for subsequent requests
 */
final class LoggedIn
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
