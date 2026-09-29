<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\Testing\ArrayLockoutStore;
use Hydra\Throttle\Testing\LockoutStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArrayLockoutStore::class)]
#[CoversClass(LockoutStoreContractTestCase::class)]
#[CoversClass(Lockout::class)]
final class ArrayLockoutStoreTest extends LockoutStoreContractTestCase
{
    private ArrayLockoutStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayLockoutStore;
    }

    protected function store(): LockoutStoreInterface
    {
        return $this->store;
    }
}
