<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use JsonException;

/** One status report from a running hub. */
final readonly class HubReport
{
    public function __construct(
        public int $pid,
        public int $startedAt,
        public int $connections,
        public bool $subscribed,
        public int $writtenAt,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'pid' => $this->pid,
            'started_at' => $this->startedAt,
            'connections' => $this->connections,
            'redis' => $this->subscribed ? 'subscribed' : 'reconnecting',
            'written_at' => $this->writtenAt,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($data)
            || !is_int($data['pid'] ?? null)
            || !is_int($data['started_at'] ?? null)
            || !is_int($data['connections'] ?? null)
            || !in_array($data['redis'] ?? null, ['subscribed', 'reconnecting'], true)
            || !is_int($data['written_at'] ?? null)
        ) {
            return null;
        }

        return new self($data['pid'], $data['started_at'], $data['connections'], $data['redis'] === 'subscribed', $data['written_at']);
    }
}
