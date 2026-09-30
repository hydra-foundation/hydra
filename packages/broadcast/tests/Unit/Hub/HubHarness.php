<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\Hub\Subscriber;
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

        return new Server($listener, $this->subscriber, $this->tokens, $config, $this->clock, $this->logger, $this->status);
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

/** A subscriber the test hands envelopes to; a socket pair wakes the loop. */
final class HandFedSubscriber implements Subscriber
{
    /** @var list<Envelope> */
    private array $queue = [];

    /** @var array{resource, resource} */
    private array $pair;

    private bool $subscribed = true;
    private bool $resubscribed = false;

    public function __construct()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('No socket pair.');
        }

        stream_set_blocking($pair[0], false);
        $this->pair = $pair;
    }

    public function push(Envelope $envelope): void
    {
        $this->queue[] = $envelope;
        fwrite($this->pair[1], '.');
    }

    public function comeBack(): void
    {
        $this->subscribed = true;
        $this->resubscribed = true;
        fwrite($this->pair[1], '.');
    }

    public function goAway(): void
    {
        $this->subscribed = false;
    }

    public function socket()
    {
        return is_resource($this->pair[0]) ? $this->pair[0] : null;
    }

    public function read(): array
    {
        fread($this->pair[0], 65536);
        $queue = $this->queue;
        $this->queue = [];

        return $queue;
    }

    /** @var (callable(): void)|null runs on every maintain(), which the server calls each round */
    public $onMaintain = null;

    public function maintain(): void
    {
        if ($this->onMaintain !== null) {
            ($this->onMaintain)();
        }
    }

    public function resubscribed(): bool
    {
        $was = $this->resubscribed;
        $this->resubscribed = false;

        return $was;
    }

    public function subscribed(): bool
    {
        return $this->subscribed;
    }

    public function close(): void
    {
        foreach ($this->pair as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
