<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;

/**
 * A {@see TwoFactorStoreInterface} in memory, for tests and for an application
 * that has not written its own yet. One process, so its compare-and-set is
 * trivially atomic.
 */
final class ArrayTwoFactorStore implements TwoFactorStoreInterface
{
    /** @var array<int|string, array{secret: string, step: int|null, hashes: list<string>}> */
    private array $accounts = [];

    public function secret(AuthenticatableInterface $user): ?string
    {
        return $this->accounts[$user->getAuthIdentifier()]['secret'] ?? null;
    }

    public function claimStep(AuthenticatableInterface $user, int $step): bool
    {
        $id = $user->getAuthIdentifier();

        if (!isset($this->accounts[$id])) {
            return false;
        }

        $last = $this->accounts[$id]['step'];

        if ($last !== null && $step <= $last) {
            return false;
        }

        $this->accounts[$id]['step'] = $step;

        return true;
    }

    public function recoveryHashes(AuthenticatableInterface $user): array
    {
        return $this->accounts[$user->getAuthIdentifier()]['hashes'] ?? [];
    }

    public function spendRecoveryHash(AuthenticatableInterface $user, string $hash): bool
    {
        $id = $user->getAuthIdentifier();
        $hashes = $this->accounts[$id]['hashes'] ?? [];
        $at = array_search($hash, $hashes, true);

        if ($at === false) {
            return false;
        }

        unset($hashes[$at]);
        $this->accounts[$id]['hashes'] = array_values($hashes);

        return true;
    }

    public function enable(AuthenticatableInterface $user, string $secret, array $recoveryHashes): void
    {
        $this->accounts[$user->getAuthIdentifier()] = [
            'secret' => $secret,
            'step' => null,
            'hashes' => array_values($recoveryHashes),
        ];
    }

    public function replaceRecoveryHashes(AuthenticatableInterface $user, array $recoveryHashes): void
    {
        $id = $user->getAuthIdentifier();

        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['hashes'] = array_values($recoveryHashes);
        }
    }

    public function disable(AuthenticatableInterface $user): void
    {
        unset($this->accounts[$user->getAuthIdentifier()]);
    }
}
