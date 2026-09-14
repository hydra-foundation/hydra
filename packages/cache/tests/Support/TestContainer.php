<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A container that resolves on get() rather than on singleton(), because that
 * is the difference a provider test is looking for: a binding built eagerly
 * would open the connections the provider deliberately defers, and would hide
 * a factory that closes over the wrong container.
 */
final class TestContainer implements ContainerInterface
{
    /** @var array<string, callable(): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @param array<string, object> $services */
    public function __construct(array $services = [])
    {
        $this->resolved = $services;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new class ("Not bound: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->resolved[$id] = ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->resolved);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        $this->factories[$abstract] = is_string($concrete)
            ? static fn () => new $concrete
            : $concrete(...);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->resolved[$abstract] = $instance;
    }

    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }

    /** Whether get() has actually built the binding, as opposed to recorded it. */
    public function isResolved(string $abstract): bool
    {
        return array_key_exists($abstract, $this->resolved);
    }
}
