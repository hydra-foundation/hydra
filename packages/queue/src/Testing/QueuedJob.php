<?php

declare(strict_types=1);

namespace Hydra\Queue\Testing;

use Hydra\Queue\Contracts\JobInterface;

final readonly class QueuedJob
{
    /**
     * @param class-string<JobInterface> $job
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public string $job,
        public array $payload,
        public int $delay,
    ) {}
}
