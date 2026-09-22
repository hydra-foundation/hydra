<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Core\Testing\FakeContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayCacheServiceProvider::class)]
final class ArrayCacheServiceProviderTest extends TestCase
{
    public function test_the_store_is_an_array_store(): void
    {
        $container = new FakeContainer;
        (new ArrayCacheServiceProvider)->register($container);

        $this->assertInstanceOf(ArrayStore::class, $container->get(StoreInterface::class));
    }
}
