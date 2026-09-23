<?php

declare(strict_types=1);

namespace Hydra\Queue\Contracts;

interface QueueInterface
{
    /**
     * Queue $job to be handled with $payload, no sooner than $delay seconds
     * from now. The payload is stored as JSON: scalars, null and arrays only.
     *
     * @param class-string<JobInterface> $job
     * @param array<array-key, mixed> $payload
     */
    public function push(string $job, array $payload = [], int $delay = 0): void;
}
