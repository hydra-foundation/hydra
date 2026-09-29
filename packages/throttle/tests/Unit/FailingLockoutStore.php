<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use DateTimeImmutable;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use RuntimeException;

/** A lockout store whose database is gone: every write throws. */
final class FailingLockoutStore implements LockoutStoreInterface
{
    public function record(Lockout $lockout): void
    {
        throw new RuntimeException('database gone');
    }

    public function active(DateTimeImmutable $now): array
    {
        return [];
    }

    public function find(string $policy, string $identity): ?Lockout
    {
        return null;
    }

    public function forget(string $policy, string $identity): bool
    {
        return false;
    }

    public function prune(DateTimeImmutable $before): int
    {
        return 0;
    }
}
