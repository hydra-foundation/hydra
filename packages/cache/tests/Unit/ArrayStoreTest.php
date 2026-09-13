<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;

/**
 * The in-memory store against the shared contract. It has no behaviour of its
 * own left to cover: everything it does, the store that ships has to do too,
 * which is the whole reason the contract case is shared.
 */
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
}
