<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\HasEmailInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * Tokens that carry an address an account wants to move to, sent to that
 * address, so the move applies only once the new mailbox is reached. Bound to
 * the current address, which the move itself changes, and to the password, so
 * an owner who changes it cancels a move they did not ask for.
 */
final readonly class EmailChangeTokens
{
    private const PURPOSE = 'email-change';

    public function __construct(
        private SignedToken $tokens,
        private UserProviderInterface $users,
        private int $ttl,
    ) {}

    public function create(HasEmailInterface $user, string $email): string
    {
        return $this->tokens->mint(self::PURPOSE, $this->ttl, $user->getAuthIdentifier(), self::binding($user), $email);
    }

    /** The change $token confirms, or null when it is invalid, expired or spent. */
    public function resolve(string $token): ?EmailChange
    {
        $claims = $this->tokens->open(self::PURPOSE, $token);
        $user = $claims === null ? null : $this->users->byIdentifier($claims->id);

        if (!$user instanceof HasEmailInterface || $claims->carried === '' || !$claims->bindsTo(self::binding($user))) {
            return null;
        }

        return new EmailChange($user, $claims->carried);
    }

    private static function binding(HasEmailInterface $user): string
    {
        return $user->getAuthEmail() . "\0" . $user->getAuthPassword();
    }
}
