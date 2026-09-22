<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Core\Testing\FakeContainer;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\SessionServiceProvider;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySessionServiceProvider::class)]
final class ArraySessionServiceProviderTest extends TestCase
{
    public function test_both_contracts_resolve_to_the_same_array_store(): void
    {
        $container = new FakeContainer;
        (new ArraySessionServiceProvider)->register($container);

        $session = $container->get(SessionInterface::class);

        $this->assertInstanceOf(ArraySessionStore::class, $session);
        $this->assertSame($session, $container->get(SessionLifecycleInterface::class));
    }

    public function test_registered_after_the_real_provider_it_wins(): void
    {
        // Which is the only way an application uses it.
        $container = new FakeContainer;
        (new SessionServiceProvider)->register($container);
        (new ArraySessionServiceProvider)->register($container);

        $this->assertInstanceOf(ArraySessionStore::class, $container->get(SessionInterface::class));
        $this->assertInstanceOf(ArraySessionStore::class, $container->get(SessionLifecycleInterface::class));
    }
}
