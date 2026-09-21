<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\RedisConnection;
use Throwable;

/** Whether the cache answers, how fast, and what Redis makes of itself. */
final class CacheWidget implements PresenterInterface
{
    private const SLOW_MS = 25;

    /** Written and read back so the probe exercises the store rather than the socket. */
    private const PROBE = 'admin:health:probe';

    public function __construct(
        private readonly StoreInterface $store,
        private readonly CacheConfig $config,
    ) {}

    public function present(): array
    {
        $array = $this->config->driver === CacheConfig::ARRAY;
        $stamp = (string) hrtime(true);
        $started = hrtime(true);

        try {
            $this->store->put(self::PROBE, $stamp, 10);
            $echoed = $this->store->get(self::PROBE);
            $latency = (hrtime(true) - $started) / 1_000_000;
            $this->store->forget(self::PROBE);
        } catch (Throwable $e) {
            return [
                'status' => Status::Down,
                'headline' => 'No answer',
                'caption' => sprintf('%s:%d', $this->config->host, $this->config->port),
                'rows' => [['label' => 'Driver', 'value' => $this->config->driver]],
                'note' => $e->getMessage(),
            ];
        }

        if ($echoed !== $stamp) {
            return [
                'status' => Status::Down,
                'headline' => 'Not storing',
                'caption' => 'wrote a value, read back another',
                'rows' => [['label' => 'Driver', 'value' => $this->config->driver]],
                'note' => 'The store accepted a value and did not return it.',
            ];
        }

        $slow = $latency > self::SLOW_MS;

        return [
            // An array store is reachable and still not a cache: it is per worker.
            'status' => $array || $slow ? Status::Warning : Status::Ok,
            'headline' => Readable::millis($latency),
            'caption' => match (true) {
                $array => 'per-worker store',
                $slow => 'slow round trip',
                default => 'responding',
            },
            'rows' => [
                ['label' => 'Driver', 'value' => $this->config->driver],
                ...($array
                    ? [['label' => 'Scope', 'value' => 'this worker only']]
                    : $this->serverRows()),
            ],
            'note' => $array ? 'CACHE_STORE=array holds nothing between workers.' : null,
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function serverRows(): array
    {
        $info = $this->info();

        if ($info === null) {
            return [['label' => 'Server', 'value' => sprintf('%s:%d', $this->config->host, $this->config->port)]];
        }

        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $reads = $hits + $misses;

        return [
            ['label' => 'Version', 'value' => (string) ($info['redis_version'] ?? 'unknown')],
            ['label' => 'Memory', 'value' => (string) Readable::bytes((float) ($info['used_memory'] ?? 0))],
            ['label' => 'Clients', 'value' => number_format((int) ($info['connected_clients'] ?? 0))],
            ['label' => 'Hit rate', 'value' => $reads === 0 ? 'no reads yet' : round($hits / $reads * 100) . '%'],
        ];
    }

    /** @return array<string, string>|null */
    private function info(): ?array
    {
        try {
            /** @var array<string, string>|false $info */
            $info = RedisConnection::open($this->config)->info();

            return $info === false ? null : $info;
        } catch (Throwable) {
            return null;
        }
    }
}
