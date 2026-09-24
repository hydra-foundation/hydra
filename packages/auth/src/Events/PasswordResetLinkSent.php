<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A reset link went out to {@see $user}. Never fired for an unknown address,
 * so fire it where the mail is actually sent, not where it is requested
 */
final class PasswordResetLinkSent
{
    public function __construct(
        public readonly AuthenticatableInterface $user,
    ) {}
}
