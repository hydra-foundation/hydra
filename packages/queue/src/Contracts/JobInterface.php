<?php

declare(strict_types=1);

namespace Hydra\Queue\Contracts;

/**
 * Work done after the request that asked for it. Built by the container when
 * its turn comes, so it takes its services in the constructor and its data,
 * as pushed, in handle().
 */
interface JobInterface
{
    /**
     * Throwing counts as a failed attempt: the job is retried after its
     * backoff, or moved to failed_jobs once it is out of tries.
     *
     * @param array<array-key, mixed> $payload
     */
    public function handle(array $payload): void;
}
