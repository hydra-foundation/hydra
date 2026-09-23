<?php

declare(strict_types=1);

namespace Hydra\Auth;

/** What an opened {@see SignedToken} says: whose it is, and what it was bound to. */
final readonly class TokenClaims
{
    public function __construct(
        public int|string $id,
        private string $digest,
    ) {}

    /** Whether the token was minted over $value, compared in constant time. */
    public function bindsTo(string $value): bool
    {
        return hash_equals(SignedToken::digest($value), $this->digest);
    }
}
