<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\CacheHealthCheck;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\FakeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CacheHealthCheck::class)]
final class CacheHealthCheckTest extends TestCase
{
    public function test_a_store_that_keeps_what_it_is_given_passes(): void
    {
        $check = new CacheHealthCheck(new ArrayStore);

        $check->check();

        $this->assertSame('cache', $check->name());
    }

    public function test_a_store_that_throws_fails_with_its_reason(): void
    {
        $store = (new FakeStore)->failAll(new RuntimeException('Connection refused'));

        $this->expectExceptionMessage('Connection refused');

        (new CacheHealthCheck($store))->check();
    }

    public function test_a_store_that_forgets_what_it_was_given_fails(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('get')->willReturn(null);

        $this->expectExceptionMessage('The store accepted a value and returned another.');

        (new CacheHealthCheck($store))->check();
    }
}
