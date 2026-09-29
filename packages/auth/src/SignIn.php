<?php

declare(strict_types=1);

namespace Hydra\Auth;

use DateTimeImmutable;

/**
 * One browser signed in to one account, as the app's store keeps it. The id
 * is the guard's own, not the PHP session's: it survives the session id being
 * rotated, and listing sign-ins never shows anyone a credential.
 */
final readonly class SignIn
{
    public function __construct(
        public string $id,
        public int|string $userId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        /** Where it was last seen from, which may not be where it began. */
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
