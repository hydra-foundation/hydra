<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\StoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The in-memory store against the shared contract. It has no behaviour of its
 * own left to cover: everything it does, the store that ships has to do too,
 * which is the whole reason the contract case is shared.
 */
#[CoversClass(ArrayStore::class)]
final class ArrayStoreTest extends StoreContractTestCase
{
    private ArrayStore $store;

    private float $now;

    protected function setUp(): void
    {
        $this->now = microtime(true);
        $this->store = new ArrayStore(fn (): float => $this->now);
    }

    protected function store(): StoreInterface
    {
        return $this->store;
    }

    protected function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function test_it_reads_the_real_clock_when_given_none(): void
    {
        // The injected clock is for this suite; a store built the way an
        // application builds it still has to measure its windows against
        // something real. A window that reads back as its own length is the
        // observable half of that, and the expiry itself is held by
        // RedisStoreTest, which waits for it.
        $store = new ArrayStore;
        $store->put('brief', 'value', ttl: 60);

        $this->assertSame('value', $store->get('brief'));
        $this->assertSame(60, $store->ttl('brief'));
    }
}
