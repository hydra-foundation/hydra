<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use InvalidArgumentException;

/**
 * One budget: how many requests a client may make to something, over how long.
 *
 * The name is part of the counter key, which is what keeps budgets separate.
 * Two policies sharing a name share a counter, so a login attempt would spend
 * the same allowance as a page view, and the tighter of the two limits would
 * decide both.
 */
final readonly class RateLimitPolicy
{
    public function __construct(
        public string $name,
        public int $limit,
        public int $window,
    ) {
        // Validated here rather than where it is counted: a policy is built at
        // boot and spent on every request, so a nonsense budget should fail
        // once at startup instead of arriving as a limit nobody can satisfy.
        if ($name === '' || preg_match('/^[a-z0-9._-]+$/i', $name) !== 1) {
            throw new InvalidArgumentException(
                "Rate limit name must be one or more of [A-Za-z0-9._-]; got \"{$name}\"."
            );
        }

        if ($limit < 1) {
            throw new InvalidArgumentException("Rate limit must be at least 1; got {$limit}.");
        }

        if ($window < 1) {
            throw new InvalidArgumentException("Rate limit window must be at least 1 second; got {$window}.");
        }
    }

    /** The counter key for one client under this policy. */
    public function keyFor(string $identity): string
    {
        return "throttle:{$this->name}:{$identity}";
    }
}
