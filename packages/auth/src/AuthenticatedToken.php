<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/** A bearer token that was accepted, and the user it speaks for. */
final readonly class AuthenticatedToken
{
    public function __construct(
        public ApiToken $token,
        public AuthenticatableInterface $user,
    ) {}
}
