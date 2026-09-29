<?php

declare(strict_types=1);

namespace Hydra\Throttle\Contracts;

use DateTimeImmutable;
use Hydra\Throttle\Lockout;

/**
 * Where lockouts are recorded, so they can be listed and released: the
 * application's to implement. The cache the counters live in cannot list its
 * keys, and would list every counter if it could; this holds only the clients
 * being refused.
 */
interface LockoutStoreInterface
{
    /** Record $lockout, replacing any record for the same policy and identity. */
    public function record(Lockout $lockout): void;

    /** @return list<Lockout> those still in force at $now, the soonest to end first */
    public function active(DateTimeImmutable $now): array;

    public function find(string $policy, string $identity): ?Lockout;

    public function forget(string $policy, string $identity): bool;

    /** @return int how many lockouts that ended before $before were deleted */
    public function prune(DateTimeImmutable $before): int;
}
