<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Broadcast\BroadcastConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BroadcastConfig::class)]
final class BroadcastConfigTest extends TestCase
{
    use WritesEnvironment;

    public function test_the_defaults_publish_nowhere(): void
    {
        // An app that installs the package without configuring it gets no
        // surprise connections.
        $config = new BroadcastConfig;

        $this->assertSame(BroadcastConfig::NULL, $config->driver);
        $this->assertSame('broadcast.', $config->channelPrefix);
    }

    public function test_an_unknown_driver_is_refused_with_the_choices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Broadcast driver must be one of redis, log, null; got "pusher".');

        new BroadcastConfig('pusher');
    }

    public function test_the_environment_names_the_driver_and_the_prefix_follows_redis(): void
    {
        $config = BroadcastConfig::fromEnvironment($this->environment([
            'BROADCAST_DRIVER' => 'log',
            'REDIS_PREFIX' => 'hydra:',
        ]));

        $this->assertSame(BroadcastConfig::LOG, $config->driver);
        $this->assertSame('hydra:broadcast.', $config->channelPrefix);
    }

    public function test_an_empty_environment_gives_the_defaults(): void
    {
        $config = BroadcastConfig::fromEnvironment($this->environment([]));

        $this->assertEquals(new BroadcastConfig, $config);
    }
}
