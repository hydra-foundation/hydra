<?php

declare(strict_types=1);

namespace Hydra\Queue;

/**
 * A job claimed by one worker. The reservation is what lets that worker, and
 * only that one, finish, release or fail it: a claim taken over after going
 * stale leaves the first holder's writes matching nothing.
 */
final readonly class ReservedJob
{
    public function __construct(
        public int $id,
        public string $job,
        public string $payload,
        public int $attempts,
        public string $reservation,
    ) {}

    /**
     * Decoded here rather than on reservation, so one malformed row fails as
     * one job instead of failing every batch it is claimed in.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return Payload::decode($this->payload);
    }
}
