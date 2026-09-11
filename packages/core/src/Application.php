<?php

declare(strict_types=1);

namespace Hydra\Core;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Contracts\ServiceProviderInterface;

/**
 * Application
 *
 * The core application
 */
final class Application
{
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];
    private bool $booted = false;

    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $provider->register($this->container);
        $this->providers[] = $provider;

        if ($this->booted) {
            $provider->boot($this->container);
        }

        return $this;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function run(): void
    {
        $this->boot();

        $kernel = $this->container->get(KernelInterface::class);

        $kernel->handle();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $kernel->terminate();
    }
}
