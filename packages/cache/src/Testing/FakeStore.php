<?php

declare(strict_types=1);

namespace Hydra\Cache\Testing;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use RuntimeException;
use Throwable;

/**
 * A store that can be told to go down.
 *
 * It sits in front of a real store, an {@see ArrayStore} unless another is
 * given, so a test gets real values back. What it adds is the one thing a
 * store cannot do on demand: fail, as Redis does when it is unreachable, so
 * the code that has to survive that can be made to.
 */
final class FakeStore implements StoreInterface
{
    /** @var list<array{contains: string|null, with: Throwable}> */
    private array $failures = [];

    public function __construct(private readonly StoreInterface $store = new ArrayStore) {}

    /** Every operation on a key containing $key throws $with, before it reaches the store. */
    public function failOn(string $key, ?Throwable $with = null): self
    {
        $this->failures[] = ['contains' => $key, 'with' => $with ?? new RuntimeException('Connection refused')];

        return $this;
    }

    /** Every operation throws $with, flush included, as a store that is down would. */
    public function failAll(?Throwable $with = null): self
    {
        $this->failures[] = ['contains' => null, 'with' => $with ?? new RuntimeException('Connection refused')];

        return $this;
    }

    public function get(string $key): mixed
    {
        $this->reach($key);

        return $this->store->get($key);
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $this->reach($key);
        $this->store->put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->reach($key);
        $this->store->forget($key);
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $this->reach($key);

        return $this->store->increment($key, $by, $ttl);
    }

    public function ttl(string $key): int
    {
        $this->reach($key);

        return $this->store->ttl($key);
    }

    public function flush(): void
    {
        $this->reach(null);
        $this->store->flush();
    }

    private function reach(?string $key): void
    {
        foreach ($this->failures as $failure) {
            if ($failure['contains'] === null || ($key !== null && str_contains($key, $failure['contains']))) {
                throw $failure['with'];
            }
        }
    }
}
