<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;

final class ArrayStoreTest extends StoreContractTestCase
{
    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    protected function store(): StoreInterface
    {
        return $this->store;
    }

    public function test_flush_empties_the_whole_store(): void
    {
        $this->store->put('a', 1);
        $this->store->put('b', 2);
        $this->store->flush();

        $this->assertNull($this->store->get('a'));
        $this->assertNull($this->store->get('b'));
    }
}
