<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\SessionServiceProvider;
use Hydra\Session\Stores\NativeSessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The provider's crux is identity: SessionInterface and
 * SessionLifecycleInterface must resolve to the SAME store instance, or the
 * middleware would start one session while controllers write to another.
 */
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
        return new class implements ContainerInterface {
            /** @var array<string, callable> */
            private array $factories = [];
            /** @var array<string, mixed> */
            private array $resolved = [];

            public function get(string $id): mixed
            {
                if ($id === Environment::class) {
                    return new Environment(__DIR__); // no .env: defaults apply
                }

                if (array_key_exists($id, $this->resolved)) {
                    return $this->resolved[$id];
                }

                if (isset($this->factories[$id])) {
                    return $this->resolved[$id] = ($this->factories[$id])();
                }

                throw new class ("No binding for {$id}.") extends RuntimeException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $id === Environment::class || isset($this->factories[$id]);
            }

            public function singleton(string $abstract, callable|string $concrete): void
            {
                $this->factories[$abstract] = is_string($concrete)
                    ? static fn () => new $concrete()
                    : $concrete;
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->resolved[$abstract] = $instance;
            }

            public function bound(string $abstract): bool
            {
                return $this->has($abstract);
            }
        };
    }
}
