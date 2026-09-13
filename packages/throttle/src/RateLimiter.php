<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Http\ClientIpResolver;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Counts what a client has spent and decides whether it may spend more.
 *
 * Two things this deliberately does not do. It does not fall back to a local
 * counter when the store is unreachable: the store raises, the error handler
 * turns that into a 500, and the application refuses traffic it cannot meter
 * rather than serving it unmetered. And it does not decide who the client is,
 * which is {@see ClientIpResolver}'s job, because a limiter that reads the
 * forwarding header itself is one a caller can reset by prepending a hop.
 */
final readonly class RateLimiter
{
    /**
     * The bucket for a request whose peer is unknown. Everyone in it shares one
     * budget, which is the conservative reading: it costs an unidentifiable
     * caller nothing to vary an address it does not have, so there is no
     * identity here to spread the count across.
     */
    private const ANONYMOUS = 'unidentified';

    public function __construct(
        private StoreInterface $store,
        private ClientIpResolver $clients,
    ) {}

    /** Spend one request against $policy, or refuse with a 429 carrying Retry-After. */
    public function enforce(ServerRequestInterface $request, RateLimitPolicy $policy): RateLimitStatus
    {
        $status = $this->hit($this->identify($request), $policy);

        if (!$status->allowed) {
            throw new TooManyRequestsException($status->retryAfter);
        }

        return $status;
    }

    /**
     * Count one request against $policy for $identity.
     *
     * The counter is incremented whether or not the budget is already spent.
     * The window opened on the first hit and the store never re-arms it, so
     * counting a refused request cannot push the reset further away; leaving it
     * uncounted would only hide how hard the limit is being pushed.
     */
    public function hit(string $identity, RateLimitPolicy $policy): RateLimitStatus
    {
        $key = $policy->keyFor($identity);
        $used = $this->store->increment($key, 1, $policy->window);

        // The window can close between the increment and this read, which
        // leaves a Retry-After of 0 telling the client to retry into a window
        // that has already reopened. One second is the honest floor.
        $retryAfter = max(1, $this->store->ttl($key));

        return new RateLimitStatus($used <= $policy->limit, $policy->limit, $used, $retryAfter);
    }

    private function identify(ServerRequestInterface $request): string
    {
        return $this->clients->resolve($request) ?? self::ANONYMOUS;
    }
}
