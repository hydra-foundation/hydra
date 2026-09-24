<?php

declare(strict_types=1);

namespace Hydra\Auth\Contracts;

/**
 * Where an account's second factor lives. The application's to implement, as
 * the user provider is, and the application's to encrypt: secret() hands back
 * the base32 secret in the clear, however it was stored.
 *
 * claimStep() and spendRecoveryHash() are compare-and-set, not a read then a
 * write. Two requests carrying the same code at once must not both get in, so
 * each has to succeed for exactly one of them.
 */
interface TwoFactorStoreInterface
{
    /** The confirmed secret, or null when the account has no second factor. */
    public function secret(AuthenticatableInterface $user): ?string;

    /** Record $step as used, and say so, only if it is later than any step recorded before. */
    public function claimStep(AuthenticatableInterface $user, int $step): bool;

    /** @return list<string> the recovery codes still unspent, as hashes */
    public function recoveryHashes(AuthenticatableInterface $user): array;

    /** Remove $hash, and say so, only if it was there to remove. */
    public function spendRecoveryHash(AuthenticatableInterface $user, string $hash): bool;

    /**
     * Turn the second factor on with a confirmed secret and a fresh set of
     * recovery codes, forgetting any step recorded before.
     *
     * @param list<string> $recoveryHashes
     */
    public function enable(AuthenticatableInterface $user, string $secret, array $recoveryHashes): void;

    /** @param list<string> $recoveryHashes replaces every code, spent or not */
    public function replaceRecoveryHashes(AuthenticatableInterface $user, array $recoveryHashes): void;

    /** Forget the secret, the recorded step and the recovery codes. */
    public function disable(AuthenticatableInterface $user): void;
}
