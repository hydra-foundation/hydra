<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub\Poll;

use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SelectPoller::class)]
final class SelectPollerTest extends PollerContractTestCase
{
    protected function poller(): Poller
    {
        return new SelectPoller;
    }

    public function test_it_is_select(): void
    {
        $this->assertSame('select', (new SelectPoller)->name());
    }
}
