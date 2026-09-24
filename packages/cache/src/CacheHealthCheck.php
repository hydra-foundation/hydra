<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\HealthCheckInterface;
use RuntimeException;

/** Writes a value and reads it back, so the probe exercises the store rather than the socket. */
final class CacheHealthCheck implements HealthCheckInterface
{
    private const PROBE = 'health:probe';

    public function __construct(private readonly StoreInterface $store) {}

    public function name(): string
    {
        return 'cache';
    }

    public function check(): void
    {
        // A key per probe: two probes sharing one would read each other's stamp.
        $stamp = bin2hex(random_bytes(8));
        $key = self::PROBE . ':' . $stamp;

        $this->store->put($key, $stamp, 10);
        $echoed = $this->store->get($key);
        $this->store->forget($key);

        if ($echoed !== $stamp) {
            throw new RuntimeException('The store accepted a value and returned another.');
        }
    }
}
