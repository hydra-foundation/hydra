<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\HasEmailInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * Email verification tokens, bound to the address they were sent to, so
 * changing the address spends them. Whether a user is verified is the
 * application's to record; auth only proves the mailbox was reached.
 */
final readonly class EmailVerificationTokens
{
    private const PURPOSE = 'email-verify';

    public function __construct(
        private SignedToken $tokens,
        private UserProviderInterface $users,
        private int $ttl,
    ) {}

    public function create(HasEmailInterface $user): string
    {
        return $this->tokens->mint(self::PURPOSE, $this->ttl, $user->getAuthIdentifier(), $user->getAuthEmail());
    }

    /** The user $token was minted for, or null when it is invalid, expired or spent. */
    public function resolve(string $token): ?HasEmailInterface
    {
        $claims = $this->tokens->open(self::PURPOSE, $token);
        $user = $claims === null ? null : $this->users->byIdentifier($claims->id);

        return $user instanceof HasEmailInterface && $claims->bindsTo($user->getAuthEmail()) ? $user : null;
    }
}
