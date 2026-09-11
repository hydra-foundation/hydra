<?php

declare(strict_types=1);

namespace Hydra\Nyholm\Tests\Unit;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Nyholm\NyholmRequestProvider;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\Nyholm\Tests\Support\FakeContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Proves this provider alone fills every PSR-7 seam the kernel consumes: the
 * PSR-17 factory interfaces and the request provider. This is the binding set
 * that used to be hard-coded in the kernel's HttpServiceProvider — an app
 * registers this provider (or a rival vendor's equivalent) explicitly.
 */
final class NyholmServiceProviderTest extends TestCase
{
    private FakeContainer $container;

    protected function setUp(): void
    {
        $this->container = new FakeContainer;
        (new NyholmServiceProvider)->register($this->container);
    }

    public function test_binds_the_psr17_factory_interfaces_to_one_psr17factory(): void
    {
        $factory = $this->container->get(Psr17Factory::class);

        $this->assertInstanceOf(Psr17Factory::class, $factory);
        // One factory instance serves every PSR-17 role.
        $this->assertSame($factory, $this->container->get(ResponseFactoryInterface::class));
        $this->assertSame($factory, $this->container->get(StreamFactoryInterface::class));
    }

    public function test_binds_the_request_provider_seam_to_the_nyholm_adapter(): void
    {
        $this->assertInstanceOf(
            NyholmRequestProvider::class,
            $this->container->get(ServerRequestProviderInterface::class),
        );
    }
}
