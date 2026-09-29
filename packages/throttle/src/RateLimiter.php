<?php

declare(strict_types=1);

namespace Hydra\Throttle;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Http\ClientIpResolver;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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

    /**
     * $lockouts, $clock and $logger are OPTIONAL. With a lockout store bound,
     * the request that first goes past a budget records who is now refused
     * and until when, so an admin can see them and let them back in; left
     * out, the limiter counts exactly as it always has.
     */
    public function __construct(
        private StoreInterface $store,
        private ClientIpResolver $clients,
        private ?LockoutStoreInterface $lockouts = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
        if ($lockouts !== null && $clock === null) {
            throw new LogicException(
                'RateLimiter records lockouts with a clock; bind Psr\Clock\ClockInterface (ClockServiceProvider does).',
            );
        }
    }

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

        // Only the request that crosses the limit writes: every later refusal
        // in the window would record the same lockout again.
        if ($used === $policy->limit + 1) {
            $this->record($policy, $identity, $retryAfter);
        }

        return new RateLimitStatus($used <= $policy->limit, $policy->limit, $used, $retryAfter);
    }

    /**
     * Let $identity back in under the policy called $policy: its counter is
     * forgotten, so its next request opens a fresh window, and so is the
     * record of its lockout.
     */
    public function release(string $policy, string $identity): void
    {
        $this->store->forget(RateLimitPolicy::key($policy, $identity));
        $this->lockouts?->forget($policy, $identity);
    }

    /**
     * The record is for an admin to read, and the refusal stands without it,
     * so a store that cannot take it is logged and passed over rather than
     * turning a 429 into a 500. The counter above still raises: that is the
     * refusal itself.
     */
    private function record(RateLimitPolicy $policy, string $identity, int $retryAfter): void
    {
        if ($this->lockouts === null || $this->clock === null) {
            return;
        }

        $now = $this->clock->now();

        try {
            $this->lockouts->record(new Lockout(
                $policy->name,
                $identity,
                $now,
                $now->modify("+{$retryAfter} seconds"),
                $policy->limit,
                $policy->window,
            ));
        } catch (Throwable $e) {
            $this->logger?->warning('Could not record a lockout: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function identify(ServerRequestInterface $request): string
    {
        return $this->clients->resolve($request) ?? self::ANONYMOUS;
    }
}
