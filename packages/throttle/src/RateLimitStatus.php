<?php

declare(strict_types=1);

namespace Hydra\Throttle;

/**
 * What one request cost and what is left, as the limiter saw it.
 */
final readonly class RateLimitStatus
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $used,
        public int $retryAfter,
    ) {}

    /** Never negative: a client that overspent is at zero, not in debt. */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }
}
