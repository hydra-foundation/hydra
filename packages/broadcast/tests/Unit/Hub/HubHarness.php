<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\Testing\FakeHubStatus;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use RuntimeException;

/**
 * A server on a loopback port, a subscriber the test feeds by hand, and
 * browsers as plain client sockets. Nothing sleeps: the test runs the loop a
 * round at a time and moves the frozen clock.
 */
trait HubHarness
{
    private FrozenClock $clock;
    private CapturingLogger $logger;
    private StreamToken $tokens;
    private HandFedSubscriber $subscriber;
    private FakeHubStatus $status;
    private Server $server;
    private string $address;

    /** @var list<resource> */
    private array $browsers = [];

    #[Before]
    protected function startHub(): void
    {
        $this->clock = new FrozenClock('2026-09-30 12:00:00 UTC');
        $this->logger = new CapturingLogger;
        $this->tokens = new StreamToken(Signer::fromHex(str_repeat('ab', 32)), $this->clock);
        $this->subscriber = new HandFedSubscriber;
        $this->status = new FakeHubStatus;
        $this->server = $this->makeServer(new HubConfig(host: '127.0.0.1', port: 1));
    }

    #[After]
    protected function stopHub(): void
    {
        $this->server->stop();

        foreach ($this->browsers as $browser) {
            if (is_resource($browser)) {
                fclose($browser);
            }
        }

        $this->subscriber->close();
    }

    private function makeServer(HubConfig $config): Server
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($listener === false) {
            throw new RuntimeException("Could not listen: {$error}");
        }

        $this->address = (string) stream_socket_get_name($listener, false);

        return new Server($listener, $this->subscriber, $this->tokens, $config, $this->clock, $this->logger, $this->status, $this->poller());
    }

    /** What the hub waits through; the IoPoll variants of each test override it. */
    protected function poller(): Poller
    {
        return new SelectPoller;
    }

    /**
     * A browser that has connected and sent $head.
     *
     * @return resource
     */
    private function browser(string $head)
    {
        $socket = stream_socket_client("tcp://{$this->address}", $errno, $error, 1.0);

        if ($socket === false) {
            throw new RuntimeException("Could not connect: {$error}");
        }

        stream_set_blocking($socket, false);
        $this->browsers[] = $socket;

        if ($head !== '') {
            fwrite($socket, $head);
        }

        $this->rounds();

        return $socket;
    }

    /**
     * @param list<string> $topics
     * @return resource a browser holding an open stream on $topics
     */
    private function listener(array $topics = ['demo'], int $ttl = 3600, int|string $user = 1)
    {
        $browser = $this->browser(self::get($this->tokens->mint($user, $topics, $ttl)));
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $this->drain($browser));

        return $browser;
    }

    private static function get(string $token, string $path = '/stream'): string
    {
        return "GET {$path}?token={$token} HTTP/1.1\r\nHost: hub\r\n\r\n";
    }

    /** Runs the loop until it has had a quiet round, and at most a few. */
    private function rounds(int $max = 6): void
    {
        for ($i = 0; $i < $max; $i++) {
            $this->server->tick(0.005);
        }
    }

    /** @param resource $browser what has arrived on $browser since last drained */
    private function drain($browser): string
    {
        $this->rounds();
        $got = '';

        for ($i = 0; $i < 50; $i++) {
            $chunk = fread($browser, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $got .= $chunk;
        }

        return $got;
    }

    /** @param resource $browser */
    private function closedByServer($browser): bool
    {
        $this->drain($browser);

        return feof($browser);
    }

    private function publish(Envelope $envelope): void
    {
        $this->subscriber->push($envelope);
        $this->rounds();
    }
}
