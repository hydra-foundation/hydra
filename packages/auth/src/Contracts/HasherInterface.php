<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * Hasher interface
 */
interface HasherInterface
{
    /** Hash a plaintext password for storage. */
    public function hash(string $plain): string;

    /** Whether $plain matches the stored $hash, compared in constant time. */
    public function verify(string $plain, string $hash): bool;

    /**
     * Whether $hash was made with weaker parameters than the current policy.
     * A caller that has a write path to user storage can use this after a
     * successful verify() to re-hash and persist the password on the user's next
     * login — auth ships the check; performing the write is the app's to do
     * (the user provider is read-only by design).
     */
    public function needsRehash(string $hash): bool;
}
