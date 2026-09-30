<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Testing;

use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Test counterpart to {@see \Hydra\Broadcast\BroadcastServiceProvider}: binds
 * a {@see FakeBroadcaster}, resolvable as itself for the assertions. Register
 * it after the real provider.
 */
final class FakeBroadcastServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $broadcaster = new FakeBroadcaster;

        $container->instance(FakeBroadcaster::class, $broadcaster);
        $container->instance(BroadcasterInterface::class, $broadcaster);
    }
}
