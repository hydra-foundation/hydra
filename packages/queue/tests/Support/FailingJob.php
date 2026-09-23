<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Support;

use Hydra\Queue\Contracts\JobInterface;
use RuntimeException;

final class FailingJob implements JobInterface
{
    public int $attempts = 0;

    public function handle(array $payload): void
    {
        $this->attempts++;

        throw new RuntimeException('the mail server is down');
    }
}
