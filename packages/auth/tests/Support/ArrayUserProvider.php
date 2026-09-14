<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Support;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * A user provider over an array, so the published contract case has something
 * in this repository to run against. Auth ships no provider of its own, and a
 * contract nothing subclasses is a contract that can be broken silently.
 */
final class ArrayUserProvider implements UserProviderInterface
{
    /** @var array<string, AuthenticatableInterface> keyed by username */
    private array $users = [];

    public function add(string $username, AuthenticatableInterface $user): void
    {
        $this->users[$username] = $user;
    }

    public function byIdentifier(int|string $id): ?AuthenticatableInterface
    {
        foreach ($this->users as $user) {
            if ($user->getAuthIdentifier() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function byUsername(string $username): ?AuthenticatableInterface
    {
        return $this->users[$username] ?? null;
    }
}
