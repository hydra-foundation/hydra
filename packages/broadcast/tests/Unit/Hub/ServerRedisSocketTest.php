<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use Hydra\Broadcast\Hub\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The hub follows the Redis socket across reconnects: the old one is let go. */
#[CoversClass(Server::class)]
final class ServerRedisSocketTest extends TestCase
{
    use HubHarness;

    private RecordingPoller $recording;

    public function test_a_reconnected_subscription_is_watched_and_the_old_socket_forgotten(): void
    {
        $this->server->stop();
        $this->subscriber = $subscriber = new HighSubscriber;
        $this->server = $this->makeServer(new HubConfig(host: '127.0.0.1', port: 1));
        $this->rounds(1);
        $first = (int) $subscriber->socket();

        $subscriber->reconnectHigh();
        $this->rounds(1);

        $this->assertContains($first, $this->recording->forgotten);
        $this->assertContains((int) $subscriber->socket(), $this->recording->watched);
    }

    protected function poller(): Poller
    {
        return $this->recording = new RecordingPoller;
    }
}

/** select, noting what the hub asks it to watch and to forget. */
final class RecordingPoller implements Poller
{
    /** @var list<int> */
    public array $watched = [];

    /** @var list<int> */
    public array $forgotten = [];

    private SelectPoller $inner;

    public function __construct()
    {
        $this->inner = new SelectPoller;
    }

    public function watch(mixed $socket): void
    {
        $this->watched[] = (int) $socket;
        $this->inner->watch($socket);
    }

    public function forget(mixed $socket): void
    {
        $this->forgotten[] = (int) $socket;
        $this->inner->forget($socket);
    }

    public function wantWrite(mixed $socket, bool $want): void
    {
        $this->inner->wantWrite($socket, $want);
    }

    public function wait(float $timeout): ?array
    {
        return $this->inner->wait($timeout);
    }

    public function name(): string
    {
        return 'select';
    }

    public function capacity(): int
    {
        return $this->inner->capacity();
    }
}
