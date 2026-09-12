<?php

declare(strict_types=1);

namespace Hydra\PhpDi;

use DI\Container as PhpDiContainer;
use Hydra\Core\Contracts\ContainerInterface;

use function DI\autowire;
use function DI\factory;

/**
 * The default adapter, backed by PHP-DI
 */
final class Container implements ContainerInterface
{
    public function __construct(private readonly PhpDiContainer $container) {}

    public static function create(): self
    {
        return new self(new PhpDiContainer);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function singleton(string $abstract, callable|string $concrete): void
    {
        $this->container->set($abstract, is_string($concrete)
            ? autowire($concrete)
            : factory($concrete));
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->container->set($abstract, $instance);
    }

    public function bound(string $abstract): bool
    {
        return $this->container->has($abstract);
    }
}
