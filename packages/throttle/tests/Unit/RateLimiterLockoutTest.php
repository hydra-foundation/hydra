<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use DateTimeImmutable;
use Hydra\Cache\ArrayStore;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Throttle\Testing\ArrayLockoutStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * With a lockout store bound, the request that first goes past a budget
 * records who is now refused and until when, and release() lets them back in.
 * One write per lockout: the requests either side of the crossing cost nothing.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterLockoutTest extends TestCase
{
    private FrozenClock $clock;
    private ArrayStore $counters;
    private ArrayLockoutStore $lockouts;

    /** @var AbstractLogger&object{lines: list<string>, contexts: list<array<mixed>>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-29 10:00:00');
        $this->counters = ArrayStore::withClock($this->clock);
        $this->lockouts = new ArrayLockoutStore;
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /** @var list<array<mixed>> */
            public array $contexts = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
                $this->contexts[] = $context;
            }
        };
    }

    public function test_a_store_without_a_clock_is_refused_with_the_fix(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RateLimiter records lockouts with a clock; bind Psr\Clock\ClockInterface (ClockServiceProvider does).');

        new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), $this->lockouts);
    }

    public function test_the_hit_that_goes_past_the_budget_records_the_lockout(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 2, 600);

        $limiter->hit('203.0.113.7', $policy);
        $limiter->hit('203.0.113.7', $policy);
        $this->assertSame([], $this->lockouts->active($this->clock->now()), 'at the limit, not past it');

        $this->clock->advance('+30 seconds');
        $status = $limiter->hit('203.0.113.7', $policy);

        $this->assertFalse($status->allowed);
        $this->assertEquals(
            new Lockout('login', '203.0.113.7', new DateTimeImmutable('2026-09-29 10:00:30'), new DateTimeImmutable('2026-09-29 10:10:00'), 2, 600),
            $this->lockouts->find('login', '203.0.113.7'),
        );
    }

    public function test_later_refusals_in_the_window_write_nothing(): void
    {
        $counting = new class ($this->lockouts) implements LockoutStoreInterface {
            public int $writes = 0;

            public function __construct(private readonly ArrayLockoutStore $inner) {}

            public function record(Lockout $lockout): void
            {
                $this->writes++;
                $this->inner->record($lockout);
            }

            public function active(DateTimeImmutable $now): array
            {
                return $this->inner->active($now);
            }

            public function find(string $policy, string $identity): ?Lockout
            {
                return $this->inner->find($policy, $identity);
            }

            public function forget(string $policy, string $identity): bool
            {
                return $this->inner->forget($policy, $identity);
            }

            public function prune(DateTimeImmutable $before): int
            {
                return $this->inner->prune($before);
            }
        };
        $limiter = new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), $counting, $this->clock, $this->logger);
        $policy = new RateLimitPolicy('login', 1, 600);

        for ($i = 0; $i < 6; $i++) {
            $limiter->hit('203.0.113.7', $policy);
        }

        $this->assertSame(1, $counting->writes);
    }

    public function test_crossing_again_in_a_new_window_replaces_the_record(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $this->clock->advance('+601 seconds');
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $this->assertEquals(new DateTimeImmutable('2026-09-29 10:20:01'), $this->lockouts->find('login', 'a')?->until);
        $this->assertCount(1, $this->lockouts->active($this->clock->now()));
    }

    public function test_one_client_locked_out_leaves_the_rest_alone(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);
        $limiter->hit('b', $policy);

        $this->assertNull($this->lockouts->find('login', 'b'));
    }

    public function test_release_lets_the_client_straight_back_in_and_forgets_the_record(): void
    {
        $limiter = $this->limiter();
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $limiter->release('login', 'a');

        $this->assertNull($this->lockouts->find('login', 'a'));
        $status = $limiter->hit('a', $policy);
        $this->assertTrue($status->allowed);
        $this->assertSame(1, $status->used);
    }

    public function test_release_without_a_store_still_resets_the_counter(): void
    {
        $limiter = new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()));
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);
        $limiter->hit('a', $policy);

        $limiter->release('login', 'a');

        $this->assertTrue($limiter->hit('a', $policy)->allowed);
    }

    public function test_a_store_that_fails_to_record_is_logged_and_the_refusal_stands(): void
    {
        $broken = new FailingLockoutStore;
        $limiter = new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), $broken, $this->clock, $this->logger);
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);

        $status = $limiter->hit('a', $policy);

        $this->assertFalse($status->allowed);
        $this->assertSame(['Could not record a lockout: database gone'], $this->logger->lines);
        $this->assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception'] ?? null);
    }

    public function test_a_store_that_fails_with_no_logger_bound_still_refuses(): void
    {
        $limiter = new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), new FailingLockoutStore, $this->clock);
        $policy = new RateLimitPolicy('login', 1, 600);
        $limiter->hit('a', $policy);

        $this->assertFalse($limiter->hit('a', $policy)->allowed);
    }

    public function test_without_a_store_nothing_is_recorded(): void
    {
        $limiter = new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), null, $this->clock, $this->logger);
        $policy = new RateLimitPolicy('login', 1, 600);

        $limiter->hit('a', $policy);

        $this->assertFalse($limiter->hit('a', $policy)->allowed);
        $this->assertSame([], $this->logger->lines, 'nothing tried, so nothing failed');
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->counters, new ClientIpResolver(TrustedProxies::none()), $this->lockouts, $this->clock, $this->logger);
    }
}
