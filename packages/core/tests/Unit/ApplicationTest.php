<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Contracts\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private function containerResolving(KernelInterface $kernel): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($kernel);

        return $container;
    }

    public function test_run_boots_providers_then_resolves_and_runs_kernel(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->once())->method('handle');
        $kernel->expects($this->once())->method('terminate');

        $container = $this->containerResolving($kernel);

        $provider = $this->createMock(ServiceProviderInterface::class);
        $provider->expects($this->once())->method('register')->with($container);
        $provider->expects($this->once())->method('boot')->with($container);

        (new Application($container))->register($provider)->run();
    }

    public function test_boot_is_idempotent(): void
    {
        $provider = $this->createMock(ServiceProviderInterface::class);
        $provider->expects($this->once())->method('boot');

        $app = new Application($this->containerResolving($this->createStub(KernelInterface::class)));
        $app->register($provider);

        $app->boot();
        $app->boot(); // second call must be a no-op
    }

    public function test_register_is_called_immediately_on_registration(): void
    {
        // register() wires bindings up front; boot() happens later.
        $provider = $this->createMock(ServiceProviderInterface::class);
        $provider->expects($this->once())->method('register');
        $provider->expects($this->never())->method('boot');

        $app = new Application($this->containerResolving($this->createStub(KernelInterface::class)));
        $app->register($provider);
        // no run()/boot(), so boot must not have fired yet
    }

    public function test_provider_registered_after_boot_is_booted_immediately(): void
    {
        // A provider registered post-boot must still be booted, not silently
        // left half-wired.
        $container = $this->containerResolving($this->createStub(KernelInterface::class));
        $app = new Application($container);
        $app->boot();

        $late = $this->createMock(ServiceProviderInterface::class);
        $late->expects($this->once())->method('register')->with($container);
        $late->expects($this->once())->method('boot')->with($container);

        $app->register($late);
    }
}
