<?php

declare(strict_types=1);

namespace Hydra\Cache\Testing;

use Hydra\Cache\Contracts\StoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every store owes its callers, run against each implementation.
 * Both stores are held to one set of expectations on purpose: a limiter tested
 * against ArrayStore and deployed against RedisStore is only meaningful if the
 * two agree, and the places they could drift (expiry, counter windows, what a
 * miss looks like) are exactly the places a limiter depends on.
 */
abstract class StoreContractTestCase extends TestCase
{
    abstract protected function store(): StoreInterface;

    /**
     * Move the store's clock on by $seconds.
     *
     * Expiry is the half of this contract that cannot be asserted without time
     * passing, and how it passes is the one thing the two stores legitimately
     * disagree about: a fake clock settles it for ArrayStore, while RedisStore's
     * TTLs are the server's own and can only be waited out.
     */
    abstract protected function advance(int $seconds): void;

    public function test_a_key_that_was_never_written_reads_as_nothing(): void
    {
        $this->assertNull($this->store()->get('absent'));
    }

    public function test_it_returns_what_was_put_in(): void
    {
        $store = $this->store();
        $store->put('name', 'hydra');

        $this->assertSame('hydra', $store->get('name'));
    }

    public function test_values_survive_their_own_types(): void
    {
        $store = $this->store();

        $store->put('int', 42);
        $store->put('list', ['a', 'b']);
        $store->put('bool', false);

        $this->assertSame(42, $store->get('int'));
        $this->assertSame(['a', 'b'], $store->get('list'));
        // false is a value, not a miss: the distinction a naive store loses.
        $this->assertFalse($store->get('bool'));
        $this->assertNotNull($store->get('bool'));
    }

    public function test_a_forgotten_key_is_gone(): void
    {
        $store = $this->store();
        $store->put('name', 'hydra');
        $store->forget('name');

        $this->assertNull($store->get('name'));
    }

    public function test_an_expired_value_reads_as_nothing(): void
    {
        $store = $this->store();
        $store->put('brief', 'value', ttl: 1);

        $this->assertSame('value', $store->get('brief'));

        $this->advance(2);

        $this->assertNull($store->get('brief'));
    }

    public function test_incrementing_an_absent_key_starts_at_the_step(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->increment('hits'));
        $this->assertSame(3, $store->increment('hits', 2));
        $this->assertSame(3, $store->get('hits'));
    }

    public function test_the_first_increment_opens_the_window(): void
    {
        $store = $this->store();
        $store->increment('hits', 1, ttl: 60);

        $this->assertGreaterThan(0, $store->ttl('hits'));
        $this->assertLessThanOrEqual(60, $store->ttl('hits'));
    }

    public function test_a_later_increment_does_not_extend_the_window(): void
    {
        // The property the whole limiter rests on. Re-arming the window on every
        // hit lets a steady stream of requests hold a counter open indefinitely
        // without the limit it belongs to ever coming due.
        $store = $this->store();
        $store->increment('hits', 1, ttl: 10);

        $this->advance(2);
        $store->increment('hits', 1, ttl: 10);

        $this->assertLessThanOrEqual(8, $store->ttl('hits'), 'the window must measure from the first hit');
        $this->assertSame(2, $store->get('hits'));
    }

    public function test_a_counter_clears_when_its_window_closes(): void
    {
        $store = $this->store();
        $store->increment('hits', 1, ttl: 1);

        $this->advance(2);

        $this->assertNull($store->get('hits'));
        $this->assertSame(1, $store->increment('hits', 1, ttl: 1), 'the next window starts fresh');
    }

    public function test_a_counter_with_no_window_never_expires(): void
    {
        $store = $this->store();
        $store->increment('hits');

        $this->assertSame(0, $store->ttl('hits'));
    }

    public function test_flush_empties_the_whole_store(): void
    {
        $store = $this->store();
        $store->put('a', 1);
        $store->increment('hits', 1, ttl: 60);
        $store->flush();

        $this->assertNull($store->get('a'));
        $this->assertNull($store->get('hits'));
        $this->assertSame(0, $store->ttl('hits'));
    }

    public function test_an_absent_key_has_no_time_left(): void
    {
        // Not an error and not a negative: "nothing is going to clear" is the
        // same answer whether the key is missing or simply permanent.
        $this->assertSame(0, $this->store()->ttl('absent'));
    }
}
