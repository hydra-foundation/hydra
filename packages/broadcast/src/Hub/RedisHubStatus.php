<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use Closure;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use RuntimeException;

/**
 * The status as one Redis key with an expiry, on a connection of its own:
 * the subscriber's is in subscribe mode and can do nothing else.
 */
final class RedisHubStatus implements HubStatus
{
    private ?Redis $redis = null;

    /** @param Closure(): Redis $open */
    public function __construct(
        private readonly Closure $open,
        private readonly string $key,
        private readonly LoggerInterface $logger,
    ) {}

    public function publish(HubReport $report, int $ttl): void
    {
        $this->quietly(fn (Redis $redis) => $redis->set($this->key, $report->toJson(), ['EX' => $ttl]));
    }

    public function clear(): void
    {
        $this->quietly(fn (Redis $redis) => $redis->del($this->key));
    }

    public function read(): ?HubReport
    {
        try {
            $json = $this->redis()->get($this->key);
        } catch (RedisException $e) {
            $this->redis = null;

            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        return is_string($json) ? HubReport::fromJson($json) : null;
    }

    /** @param Closure(Redis): mixed $command */
    private function quietly(Closure $command): void
    {
        try {
            $command($this->redis());
        } catch (RuntimeException $e) {
            // RedisException is a RuntimeException too.
            $this->redis = null;
            $this->logger->warning("Could not write the SSE hub's status: {$e->getMessage()}");
        }
    }

    private function redis(): Redis
    {
        return $this->redis ??= ($this->open)();
    }
}
