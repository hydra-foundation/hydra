<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use DateTimeImmutable;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SignIn;

/**
 * A {@see SignInStoreInterface} in memory, for tests and for an application
 * that has not written its own yet.
 */
final class ArraySignInStore implements SignInStoreInterface
{
    /** @var array<string, SignIn> */
    private array $signIns = [];

    public function create(string $id, AuthenticatableInterface $user, DateTimeImmutable $at): SignIn
    {
        return $this->signIns[$id] = new SignIn($id, $user->getAuthIdentifier(), $at, $at);
    }

    public function find(string $id): ?SignIn
    {
        return $this->signIns[$id] ?? null;
    }

    public function forUser(AuthenticatableInterface $user): array
    {
        $mine = array_values(array_filter($this->signIns, fn (SignIn $s): bool => self::owns($user, $s)));

        usort($mine, static fn (SignIn $a, SignIn $b): int => $b->lastSeenAt <=> $a->lastSeenAt);

        return $mine;
    }

    public function touch(string $id, DateTimeImmutable $at, ?string $ip, ?string $userAgent): void
    {
        $signIn = $this->signIns[$id] ?? null;

        if ($signIn !== null) {
            $this->signIns[$id] = new SignIn($signIn->id, $signIn->userId, $signIn->createdAt, $at, $ip, $userAgent);
        }
    }

    public function revoke(string $id): bool
    {
        if (!isset($this->signIns[$id])) {
            return false;
        }

        unset($this->signIns[$id]);

        return true;
    }

    public function revokeAll(AuthenticatableInterface $user, ?string $except = null): int
    {
        $before = count($this->signIns);
        $this->signIns = array_filter(
            $this->signIns,
            static fn (SignIn $s): bool => !self::owns($user, $s) || $s->id === $except,
        );

        return $before - count($this->signIns);
    }

    public function prune(DateTimeImmutable $before): int
    {
        $count = count($this->signIns);
        $this->signIns = array_filter($this->signIns, static fn (SignIn $s): bool => $s->lastSeenAt >= $before);

        return $count - count($this->signIns);
    }

    private static function owns(AuthenticatableInterface $user, SignIn $signIn): bool
    {
        return (string) $signIn->userId === (string) $user->getAuthIdentifier();
    }
}
