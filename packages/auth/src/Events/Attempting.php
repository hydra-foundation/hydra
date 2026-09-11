<?php

declare(strict_types=1);

namespace Hydra\Auth\Events;

/**
 * Attempting
 *
 * A credential check is about to run for {@see $username}
 */
final class Attempting
{
    public function __construct(
        public readonly string $username,
    ) {}
}
