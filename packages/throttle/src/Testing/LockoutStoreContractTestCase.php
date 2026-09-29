<?php

declare(strict_types=1);

namespace Hydra\Throttle\Testing;

use DateTimeImmutable;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see LockoutStoreInterface} has to do, the fake included. Extend
 * it with a store over your own table.
 */
abstract class LockoutStoreContractTestCase extends TestCase
{
    abstract protected function store(): LockoutStoreInterface;

    private static function lockout(string $policy, string $identity, string $at, string $until): Lockout
    {
        return new Lockout($policy, $identity, new DateTimeImmutable($at), new DateTimeImmutable($until), 5, 600);
    }

    public function test_a_recorded_lockout_is_found_by_its_policy_and_identity(): void
    {
        $store = $this->store();
        $store->record(self::lockout('login', '203.0.113.7', '2026-09-29 10:00:00', '2026-09-29 10:10:00'));

        $found = $store->find('login', '203.0.113.7');

        $this->assertNotNull($found);
        $this->assertSame('login', $found->policy);
        $this->assertSame('203.0.113.7', $found->identity);
        $this->assertSame('2026-09-29 10:00:00', $found->lockedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 10:10:00', $found->until->format('Y-m-d H:i:s'));
        $this->assertSame(5, $found->limit);
        $this->assertSame(600, $found->window);
        $this->assertNull($store->find('login', '198.51.100.2'));
        $this->assertNull($store->find('login-account', '203.0.113.7'));
    }

    public function test_a_second_lockout_for_the_same_client_replaces_the_first(): void
    {
        $store = $this->store();
        $store->record(self::lockout('login', '203.0.113.7', '2026-09-29 10:00:00', '2026-09-29 10:10:00'));
        $store->record(self::lockout('login', '203.0.113.7', '2026-09-29 11:00:00', '2026-09-29 11:10:00'));

        $this->assertSame('2026-09-29 11:10:00', $store->find('login', '203.0.113.7')?->until->format('Y-m-d H:i:s'));
        $this->assertCount(1, $store->active(new DateTimeImmutable('2026-09-29 11:00:00')));
    }

    public function test_active_lists_what_is_still_in_force_soonest_to_end_first(): void
    {
        $store = $this->store();
        $store->record(self::lockout('login', 'a', '2026-09-29 10:00:00', '2026-09-29 10:30:00'));
        $store->record(self::lockout('login', 'b', '2026-09-29 10:00:00', '2026-09-29 10:10:00'));
        $store->record(self::lockout('global', 'c', '2026-09-29 09:00:00', '2026-09-29 10:05:00'));

        $active = $store->active(new DateTimeImmutable('2026-09-29 10:05:00'));

        $this->assertSame(['b', 'a'], array_map(static fn (Lockout $l): string => $l->identity, $active), 'one ending exactly now is over');
    }

    public function test_forget_removes_the_lockout_and_says_whether_there_was_one(): void
    {
        $store = $this->store();
        $store->record(self::lockout('login', 'a', '2026-09-29 10:00:00', '2026-09-29 10:10:00'));

        $this->assertTrue($store->forget('login', 'a'));
        $this->assertNull($store->find('login', 'a'));
        $this->assertFalse($store->forget('login', 'a'));
    }

    public function test_prune_removes_what_ended_before_the_boundary(): void
    {
        $store = $this->store();
        $store->record(self::lockout('login', 'a', '2026-09-29 09:00:00', '2026-09-29 09:59:59'));
        $store->record(self::lockout('login', 'b', '2026-09-29 09:00:00', '2026-09-29 10:00:00'));

        $this->assertSame(1, $store->prune(new DateTimeImmutable('2026-09-29 10:00:00')));
        $this->assertNull($store->find('login', 'a'));
        $this->assertNotNull($store->find('login', 'b'));
    }
}
