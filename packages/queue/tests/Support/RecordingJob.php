<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Support;

use Hydra\Queue\Contracts\JobInterface;

final class RecordingJob implements JobInterface
{
    /** @var list<array<array-key, mixed>> */
    public array $handled = [];

    public function handle(array $payload): void
    {
        $this->handled[] = $payload;
    }
}
