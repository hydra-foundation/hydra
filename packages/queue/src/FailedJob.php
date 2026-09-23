<?php

declare(strict_types=1);

namespace Hydra\Queue;

final readonly class FailedJob
{
    public function __construct(
        public int $id,
        public string $job,
        public string $payload,
        public string $exception,
        public int $failedAt,
    ) {}

    /** The exception's class and message, without where it was thrown or the trace. */
    public function reason(): string
    {
        return (string) preg_replace('/ in \S+:\d+$/', '', strtok($this->exception, "\n") ?: '');
    }
}
