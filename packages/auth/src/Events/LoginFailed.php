<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

/**
 * A credential check for {@see $username} did not authenticate anyone
 */
final class LoginFailed
{
    public function __construct(
        public readonly string $username,
    ) {}
}
