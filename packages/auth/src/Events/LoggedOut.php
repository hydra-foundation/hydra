<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

/**
 * The authenticated user was forgotten
 */
final class LoggedOut
{
    public function __construct(
        public readonly int|string|null $userId,
    ) {}
}
