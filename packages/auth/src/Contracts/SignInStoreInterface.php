<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

use DateTimeImmutable;
use Hydra\Auth\SignIn;

/**
 * Where sign-ins are recorded, so they can be listed and revoked: the
 * application's to implement, as the user provider is. Bound, it makes
 * SessionGuard write a record on login and check it on every request after;
 * deleting the record is revoking the sign-in.
 */
interface SignInStoreInterface
{
    /** A new sign-in for $user under $id, which the guard generated. Seen at $at. */
    public function create(string $id, AuthenticatableInterface $user, DateTimeImmutable $at): SignIn;

    public function find(string $id): ?SignIn;

    /** @return list<SignIn> most recently seen first */
    public function forUser(AuthenticatableInterface $user): array;

    /** Last seen at $at, from $ip with $userAgent. */
    public function touch(string $id, DateTimeImmutable $at, ?string $ip, ?string $userAgent): void;

    /**
     * Remove the sign-in, and say whether there was one. Takes no owner: the id
     * is 128 random bits held by the session and whoever lists it, and a
     * caller that must check ownership compares find()->userId first.
     */
    public function revoke(string $id): bool;

    /** @return int how many of $user's sign-ins were removed, $except kept */
    public function revokeAll(AuthenticatableInterface $user, ?string $except = null): int;

    /** @return int how many sign-ins last seen before $before were removed */
    public function prune(DateTimeImmutable $before): int;
}
