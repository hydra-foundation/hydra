<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\SessionServiceProvider;
use Hydra\Session\Stores\NativeSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The provider's crux is identity: SessionInterface and
 * SessionLifecycleInterface must resolve to the SAME store instance, or the
 * middleware would start one session while controllers write to another.
 */
#[CoversClass(SessionServiceProvider::class)]
final class SessionServiceProviderTest extends TestCase
{
    public function test_both_contracts_resolve_to_the_same_store_instance(): void
    {
        $container = $this->container();
        (new SessionServiceProvider)->register($container);

        $session = $container->get(SessionInterface::class);

        $this->assertInstanceOf(NativeSessionStore::class, $session);
        $this->assertSame($session, $container->get(SessionLifecycleInterface::class));
    }

    /** A minimal strict container, preloaded with the Environment the config needs. */
    private function container(): ContainerInterface
    {
        return new FakeContainer([Environment::class => new Environment(__DIR__)]); // no .env: defaults apply
    }
}
