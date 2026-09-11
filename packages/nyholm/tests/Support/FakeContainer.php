<?php

declare(strict_types=1);

namespace Hydra\Nyholm\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A tiny strict container for the provider test — enough to register and
 * resolve the bindings without pulling php-di. No autowiring: everything the
 * provider needs, it must bind itself.
 */
final class FakeContainer implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, mixed> */
    private array $resolved = [];

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
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
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        if (is_string($concrete)) {
            $this->factories[$abstract] = static fn () => new $concrete();
            return;
        }

        $this->factories[$abstract] = $concrete;
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }
}
