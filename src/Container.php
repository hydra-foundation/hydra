<?php

declare(strict_types=1);

namespace Hydra\PhpDi;

use DI\Container as PhpDiContainer;
use Hydra\Core\Contracts\ContainerInterface;

use function DI\autowire;
use function DI\factory;

/**
 * The default {@see ContainerInterface} adapter, backed by PHP-DI.
 *
 * `hydrakit/core` defines the container contract the framework resolves through
 * but names no DI engine; this package is the one place PHP-DI is named, exactly
 * as `hydrakit/nyholm` is the one place a PSR-7 vendor is named. An app that
 * prefers another engine writes its own adapter against the same interface and
 * this package never loads.
 *
 * PHP-DI caches every resolved entry, so a `set()` binding is inherently shared:
 * {@see singleton()} is the only binding semantics there is, and there is no
 * transient counterpart (matching the contract's documented YAGNI stance).
 */
final class Container implements ContainerInterface
{
    public function __construct(private readonly PhpDiContainer $container) {}

    /**
     * Build the adapter over a fresh PHP-DI container — the common case, so an
     * app's composition root needn't name `DI\Container` itself. Inject a
     * pre-configured container through the constructor instead when you need to
     * tune PHP-DI (definitions, compilation, proxies).
     */
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
        // A class-string is autowired; a callable is used as a factory. PHP-DI's
        // set() would store a bare string as a literal value, so the class-string
        // case must be wrapped in autowire() (else get() returns the string, not
        // an instance) and the callable in factory().
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
