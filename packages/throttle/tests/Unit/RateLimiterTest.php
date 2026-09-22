<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\FakeStore;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * What the limiter counts and, more to the point, who it counts it against. A
 * budget is only worth the identity it is keyed on: if a caller can vary that,
 * the limit is a formality.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private ArrayStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
        $this->store = ArrayStore::withClock($this->clock);
    }

    public function test_requests_inside_the_budget_are_allowed(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 3, 60);

        $first = $limiter->enforce($this->request('198.51.100.7'), $policy);
        $limiter->enforce($this->request('198.51.100.7'), $policy);
        $third = $limiter->enforce($this->request('198.51.100.7'), $policy);

        $this->assertTrue($first->allowed);
        $this->assertSame(2, $first->remaining());
        $this->assertSame(0, $third->remaining());
    }

    public function test_the_request_past_the_budget_is_refused_with_a_retry_after(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter->enforce($this->request('198.51.100.7'), $policy);

        try {
            $limiter->enforce($this->request('198.51.100.7'), $policy);
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->status());
            // Seconds, and never zero: a client told to retry immediately would
            // walk straight back into the same closed window.
            $this->assertGreaterThan(0, $e->retryAfter());
            $this->assertLessThanOrEqual(60, $e->retryAfter());
            $this->assertSame((string) $e->retryAfter(), $e->headers()['Retry-After']);

            return;
        }

        $this->fail('The request past the limit was not refused.');
    }

    public function test_two_clients_do_not_spend_each_others_budget(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter->enforce($this->request('198.51.100.7'), $policy);
        $other = $limiter->enforce($this->request('203.0.113.9'), $policy);

        $this->assertTrue($other->allowed);
    }

    public function test_two_policies_do_not_spend_each_others_budget(): void
    {
        // The name is what keeps them apart. Sharing one would make a page view
        // cost a login attempt, and the tighter limit would decide both.
        $limiter = $this->limiter();

        $limiter->enforce($this->request('198.51.100.7'), new RateLimitPolicy('login', 1, 60));
        $status = $limiter->enforce($this->request('198.51.100.7'), new RateLimitPolicy('global', 1, 60));

        $this->assertTrue($status->allowed);
    }

    public function test_a_caller_cannot_reset_its_budget_with_a_forwarding_header(): void
    {
        // The attack the whole identity chain exists to stop. With no trusted
        // proxy declared the header is noise, so varying it buys nothing.
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter->enforce($this->request('198.51.100.7', '1.1.1.1'), $policy);

        $this->expectException(TooManyRequestsException::class);

        $limiter->enforce($this->request('198.51.100.7', '2.2.2.2'), $policy);
    }

    public function test_a_forged_hop_behind_a_trusted_proxy_does_not_buy_a_new_budget(): void
    {
        // Behind a real proxy the header IS read, from the right. The caller's
        // own prepended hop is left of what the proxy saw, so it never decides.
        $limiter = $this->limiter(new TrustedProxies(['10.0.0.1']));
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter->enforce($this->request('10.0.0.1', '1.1.1.1, 198.51.100.7'), $policy);

        $this->expectException(TooManyRequestsException::class);

        $limiter->enforce($this->request('10.0.0.1', '2.2.2.2, 198.51.100.7'), $policy);
    }

    public function test_clients_with_no_address_share_one_budget(): void
    {
        // Nothing identifies them, so there is no identity to spread the count
        // across. One shared bucket is the conservative reading.
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 60);

        $limiter->enforce(new ServerRequest('GET', 'http://hydra.test/'), $policy);

        $this->expectException(TooManyRequestsException::class);

        $limiter->enforce(new ServerRequest('GET', 'http://hydra.test/'), $policy);
    }

    public function test_a_refused_request_does_not_push_the_window_further_away(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 2);

        $limiter->enforce($this->request('198.51.100.7'), $policy);
        $this->advance(1);

        try {
            $limiter->enforce($this->request('198.51.100.7'), $policy);
        } catch (TooManyRequestsException $e) {
            // The window opened on the first hit. If a refusal re-armed it, a
            // caller could hold its own lockout open indefinitely by retrying.
            $this->assertLessThanOrEqual(1, $e->retryAfter());
        }

        $this->advance(2);

        $this->assertTrue($limiter->enforce($this->request('198.51.100.7'), $policy)->allowed);
    }

    public function test_an_unreachable_store_refuses_traffic_rather_than_serving_it_unmetered(): void
    {
        // The fail-open a limiter cannot have. The store raising is the correct
        // outcome: the error handler turns it into a 500, and the application
        // stops serving requests it has no way to count.
        $limiter = new RateLimiter((new FakeStore)->failAll(), new ClientIpResolver(TrustedProxies::none()));

        $this->expectException(RuntimeException::class);

        $limiter->enforce($this->request('198.51.100.7'), new RateLimitPolicy('login', 1, 60));
    }

    public function test_hit_counts_one_request_and_reports_the_budget(): void
    {
        // The public half of the limiter: enforce() is what middleware calls,
        // but hit() is what an application calls when it wants the status
        // without the exception — a login form showing attempts remaining.
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 3, 60);

        $first = $limiter->hit('198.51.100.7', $policy);
        $second = $limiter->hit('198.51.100.7', $policy);

        $this->assertTrue($first->allowed);
        $this->assertSame(1, $first->used);
        $this->assertSame(3, $first->limit);
        $this->assertSame(2, $second->used);
    }

    public function test_retry_after_is_never_zero(): void
    {
        // The window can close between the increment and the TTL read, and a
        // Retry-After of 0 tells the client to come straight back — which is
        // what it was just refused for. One second is the honest floor.
        $store = new class (new ArrayStore) implements StoreInterface {
            public function __construct(private readonly ArrayStore $inner) {}

            public function ttl(string $key): int
            {
                return 0;
            }

            public function get(string $key): mixed
            {
                return $this->inner->get($key);
            }

            public function put(string $key, mixed $value, int $ttl = 0): void
            {
                $this->inner->put($key, $value, $ttl);
            }

            public function forget(string $key): void
            {
                $this->inner->forget($key);
            }

            public function increment(string $key, int $by = 1, int $ttl = 0): int
            {
                return $this->inner->increment($key, $by, $ttl);
            }

            public function flush(): void
            {
                $this->inner->flush();
            }
        };
        $limiter = new RateLimiter($store, new ClientIpResolver(TrustedProxies::none()));

        $status = $limiter->hit('198.51.100.7', new RateLimitPolicy('login', 1, 60));

        $this->assertSame(1, $status->retryAfter);
    }

    /**
     * Move the store's clock on. The limiter keeps no clock of its own: every
     * window it reports is the store's TTL, so advancing that advances the
     * limiter's whole view of time.
     */
    private function advance(int $seconds): void
    {
        $this->clock->advance("+{$seconds} seconds");
    }

    private function limiter(?TrustedProxies $proxies = null): RateLimiter
    {
        return new RateLimiter($this->store, new ClientIpResolver($proxies ?? TrustedProxies::none()));
    }

    private function request(string $peer, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = new ServerRequest('GET', 'http://hydra.test/', serverParams: ['REMOTE_ADDR' => $peer]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }
}
