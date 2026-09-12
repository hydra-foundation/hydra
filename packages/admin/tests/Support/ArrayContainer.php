<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use RuntimeException;

/** A container with no autowiring: what a test puts in is all it can resolve. */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services = []) {}

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? throw new RuntimeException("Not bound: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        $this->services[$abstract] = is_callable($concrete) ? $concrete() : new $concrete;
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->services[$abstract] = $instance;
    }

    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }
}
