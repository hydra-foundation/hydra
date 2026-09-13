<?php

declare(strict_types=1);

namespace Hydra\Cache\Contracts;

/**
 * A key/value store with expiry. The counter methods are the reason this
 * contract is narrow: anything that
 * budgets per client has to increment and expire as one step. Doing it in two
 * calls leaves a window where concurrent requests each see a fresh counter, and
 * a limiter with that window can be walked straight past. Atomicity is part of
 * the contract, not an implementation detail.
 */
interface StoreInterface
{
    /** The stored value, or null when the key is absent or expired. */
    public function get(string $key): mixed;

    /** Store $value under $key for $ttl seconds; 0 means it never expires. */
    public function put(string $key, mixed $value, int $ttl = 0): void;

    public function forget(string $key): void;

    /**
     * Add $by to $key and return the new total, starting a $ttl-second window
     * on the first increment. A key that already has a window keeps it, so the
     * window measures from the first hit rather than sliding away from the
     * limit with every request that follows.
     */
    public function increment(string $key, int $by = 1, int $ttl = 0): int;

    /**
     * Seconds until $key expires. 0 both when the key is absent and when it has
     * no expiry: the question is "how long until it clears", and neither of
     * those ever will.
     */
    public function ttl(string $key): int;

    /**
     * Drop everything this store owns. Part of the contract because the tests
     * that stand in for a shared store reset between cases, and a method only
     * one implementation has is a hole in the premise those tests rest on: that
     * what passes against ArrayStore describes the store that ships.
     */
    public function flush(): void;
}
