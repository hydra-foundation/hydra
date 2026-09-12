<?php

declare(strict_types=1);

namespace Hydra\Cache;

use Hydra\Cache\Contracts\StoreInterface;

/**
 * The store that lives and dies with the process. It exists for tests and for
 * single-process tooling, and it is deliberately
 * NOT the fallback when Redis is unreachable: php-fpm runs many workers, so a
 * per-process counter would hand each worker its own budget and quietly
 * multiply every limit by the pool size. A limiter that cannot reach its store
 * should say so, not keep counting into a bucket nobody else can see.
 */
final class ArrayStore implements StoreInterface
{
    /** @var array<string, array{mixed, float|null}> value and its expiry timestamp */
    private array $entries = [];

    public function get(string $key): mixed
    {
        if ($this->expired($key)) {
            unset($this->entries[$key]);

            return null;
        }

        return $this->entries[$key][0] ?? null;
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $this->entries[$key] = [$value, $ttl > 0 ? $this->now() + $ttl : null];
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $current = $this->get($key);
        $total = (is_int($current) ? $current : 0) + $by;

        // An existing window is left alone: re-arming it on every hit would let
        // a steady stream of requests hold the counter open forever without
        // ever tripping the limit it belongs to.
        $expiry = $this->entries[$key][1] ?? null;

        if ($expiry === null && $ttl > 0) {
            $expiry = $this->now() + $ttl;
        }

        $this->entries[$key] = [$total, $expiry];

        return $total;
    }

    public function ttl(string $key): int
    {
        if ($this->expired($key)) {
            unset($this->entries[$key]);

            return 0;
        }

        $expiry = $this->entries[$key][1] ?? null;

        if ($expiry === null) {
            return 0;
        }

        // Round up: a caller told "0 seconds left" on a key that has not
        // actually cleared yet would retry into the same closed window.
        return max(0, (int) ceil($expiry - $this->now()));
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    private function expired(string $key): bool
    {
        $expiry = $this->entries[$key][1] ?? null;

        return $expiry !== null && $expiry <= $this->now();
    }

    private function now(): float
    {
        return microtime(true);
    }
}
