<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\Poll\IoPoller;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\Hub\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhp;

/** Every ServerTest case again, the hub waiting through Io\Poll instead of select. */
#[CoversClass(Server::class)]
#[CoversClass(Stream::class)]
#[CoversClass(IoPoller::class)]
#[RequiresPhp('>=8.6')]
final class ServerOnIoPollTest extends ServerTest
{
    protected function poller(): Poller
    {
        return new IoPoller;
    }
}
