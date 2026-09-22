<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use Hydra\Core\Contracts\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A container over two arrays, for a test that wires a provider by hand.
 *
 * Nothing is autowired. A class name given to singleton() is built with no
 * arguments, and anything with dependencies wants a factory, which keeps what
 * a provider test depends on written down in the test.
 */
final class FakeContainer implements ContainerInterface
{
    /** @var array<string, callable(): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @param array<string, object> $instances bound as though by instance() */
    public function __construct(array $instances = [])
    {
        $this->resolved = $instances;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        $factory = $this->factories[$id]
            ?? throw new class ("Nothing is bound to {$id}.") extends RuntimeException implements NotFoundExceptionInterface {};

        return $this->resolved[$id] = $factory();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->resolved);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        unset($this->resolved[$abstract]);

        $this->factories[$abstract] = is_string($concrete)
            ? static fn (): object => new $concrete
            : $concrete(...);
    }

    public function instance(string $abstract, object $instance): void
    {
        unset($this->factories[$abstract]);

        $this->resolved[$abstract] = $instance;
    }

    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }

    /** Whether get() has built the binding, as opposed to it only being registered. */
    public function isResolved(string $abstract): bool
    {
        return array_key_exists($abstract, $this->resolved);
    }
}
