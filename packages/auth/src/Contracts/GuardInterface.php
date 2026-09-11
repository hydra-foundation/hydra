<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * Guard interface
 *
 * Who is authenticated for the current request, and the verbs to change that
 */
interface GuardInterface
{
    /** Whether a user is authenticated for this request. */
    public function check(): bool;

    /** The authenticated user, or null when none is logged in. */
    public function user(): ?AuthenticatableInterface;

    /**
     * The authenticated user's identifier, or null when none is logged in.
     * Cheaper than {@see user()} when you only need the id (no provider lookup).
     */
    public function id(): int|string|null;

    /**
     * Look up the user by username, verify the password, and log them in on a
     * match. Returns whether the attempt succeeded. This is the convenience verb
     * that composes the provider lookup, the hash verify, and {@see login()};
     * the same flow can be driven by hand with those pieces when needed.
     */
    public function attempt(string $username, string $password): bool;

    /**
     * Mark this user as authenticated for subsequent requests. Implementations
     * regenerate the session id to defend against fixation, the way a privilege
     * change should.
     */
    public function login(AuthenticatableInterface $user): void;

    /** Forget the authenticated user and regenerate the session id. */
    public function logout(): void;
}
