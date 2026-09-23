<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * Password reset tokens, bound to the current password hash. The reset a token
 * leads to is what spends it, and so does any other password change; an
 * outstanding token cannot be revoked on its own.
 *
 * resolve() only reads. Call it to show the form and again on submit.
 */
final readonly class PasswordResetTokens
{
    private const PURPOSE = 'password-reset';

    public function __construct(
        private SignedToken $tokens,
        private UserProviderInterface $users,
        private int $ttl,
    ) {}

    public function create(AuthenticatableInterface $user): string
    {
        return $this->tokens->mint(self::PURPOSE, $this->ttl, $user->getAuthIdentifier(), $user->getAuthPassword());
    }

    /** The user $token was minted for, or null when it is invalid, expired or spent. */
    public function resolve(string $token): ?AuthenticatableInterface
    {
        $claims = $this->tokens->open(self::PURPOSE, $token);
        $user = $claims === null ? null : $this->users->byIdentifier($claims->id);

        return $user !== null && $claims->bindsTo($user->getAuthPassword()) ? $user : null;
    }
}
