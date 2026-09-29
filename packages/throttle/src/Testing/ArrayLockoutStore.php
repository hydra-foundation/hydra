<?php

declare(strict_types=1);

namespace Hydra\Throttle\Testing;

use DateTimeImmutable;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;

/**
 * A {@see LockoutStoreInterface} in memory, for tests and for an application
 * that has not written its own yet.
 */
final class ArrayLockoutStore implements LockoutStoreInterface
{
    /** @var array<string, Lockout> */
    private array $lockouts = [];

    public function record(Lockout $lockout): void
    {
        $this->lockouts[self::key($lockout->policy, $lockout->identity)] = $lockout;
    }

    public function active(DateTimeImmutable $now): array
    {
        $active = array_values(array_filter($this->lockouts, static fn (Lockout $l): bool => $l->until > $now));

        usort($active, static fn (Lockout $a, Lockout $b): int => $a->until <=> $b->until);

        return $active;
    }

    public function find(string $policy, string $identity): ?Lockout
    {
        return $this->lockouts[self::key($policy, $identity)] ?? null;
    }

    public function forget(string $policy, string $identity): bool
    {
        $key = self::key($policy, $identity);

        if (!isset($this->lockouts[$key])) {
            return false;
        }

        unset($this->lockouts[$key]);

        return true;
    }

    public function prune(DateTimeImmutable $before): int
    {
        $count = count($this->lockouts);
        $this->lockouts = array_filter($this->lockouts, static fn (Lockout $l): bool => $l->until >= $before);

        return $count - count($this->lockouts);
    }

    private static function key(string $policy, string $identity): string
    {
        return $policy . "\0" . $identity;
    }
}
