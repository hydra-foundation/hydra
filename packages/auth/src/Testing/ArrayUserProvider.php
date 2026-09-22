<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * A user provider over an array, which counts its lookups.
 *
 * Auth ships no provider of its own, since user storage belongs to the
 * application, so this is what a test hands a guard in place of one. The
 * counts are how a test proves the guard caches: reading the user three times
 * in a request should look it up once.
 */
final class ArrayUserProvider implements UserProviderInterface
{
    /** @var array<string, AuthenticatableInterface> keyed by username */
    private array $users = [];

    private int $identifierLookups = 0;

    private int $usernameLookups = 0;

    public function add(string $username, AuthenticatableInterface $user): self
    {
        $this->users[$username] = $user;

        return $this;
    }

    public function byIdentifier(int|string $id): ?AuthenticatableInterface
    {
        $this->identifierLookups++;

        foreach ($this->users as $user) {
            if ($user->getAuthIdentifier() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function byUsername(string $username): ?AuthenticatableInterface
    {
        $this->usernameLookups++;

        return $this->users[$username] ?? null;
    }

    public function identifierLookups(): int
    {
        return $this->identifierLookups;
    }

    public function usernameLookups(): int
    {
        return $this->usernameLookups;
    }

    /** Both counts back to zero, so a test can measure only what follows. */
    public function resetLookups(): void
    {
        $this->identifierLookups = 0;
        $this->usernameLookups = 0;
    }
}
