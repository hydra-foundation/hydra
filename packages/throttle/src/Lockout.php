<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use DateTimeImmutable;

/**
 * A client refused by a policy: who, since when, until when, and the budget it
 * broke. Written once, by the request that first went past the limit, so an
 * admin can see who is locked out now and let them back in.
 */
final readonly class Lockout
{
    public function __construct(
        /** The {@see RateLimitPolicy} name. */
        public string $policy,
        /** What the policy counted by: an address, a user id, a username. */
        public string $identity,
        public DateTimeImmutable $lockedAt,
        public DateTimeImmutable $until,
        public int $limit,
        /** Seconds. */
        public int $window,
    ) {}
}
