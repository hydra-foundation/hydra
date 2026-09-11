<?php

declare(strict_types=1);

namespace Hydra\Kernel\Tests\Unit;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Kernel\Kernel;
use Hydra\Kernel\Tests\Support\TestContainer;
use Hydra\Session\Contracts\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Proves the composition helper registers the standard stack in one place — the
 * knowledge that used to drift between each app's bootstrap.
 */
final class KernelTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = new TestContainer;
        $app = Kernel::application($this->container, new Environment(__DIR__));

        $this->assertInstanceOf(Application::class, $app);
    }

    public function test_binds_the_container_and_environment(): void
    {
        $this->assertSame($this->container, $this->container->get(ContainerInterface::class));
        $this->assertInstanceOf(Environment::class, $this->container->get(Environment::class));
    }

    public function test_registers_the_session_and_event_providers(): void
    {
        // These resolve with no app-supplied dependencies, so a successful get()
        // proves the provider registered its bindings.
        $this->assertInstanceOf(SessionInterface::class, $this->container->get(SessionInterface::class));
        $this->assertInstanceOf(EventDispatcherInterface::class, $this->container->get(EventDispatcherInterface::class));
    }

    public function test_registers_the_auth_and_authorization_providers(): void
    {
        // The guard/gate can't resolve without an app's user provider, but the
        // stack having REGISTERED them is what we assert here.
        $this->assertTrue($this->container->bound(GuardInterface::class));
        $this->assertTrue($this->container->bound(GateInterface::class));
    }
}
