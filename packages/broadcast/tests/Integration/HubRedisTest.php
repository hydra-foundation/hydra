<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Integration;

use Hydra\Broadcast\BroadcastServiceProvider;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Hub\HubFactory;
use Hydra\Broadcast\Hub\HubStatus;
use Hydra\Broadcast\Hub\RedisHubStatus;
use Hydra\Broadcast\Hub\RedisSubscriber;
use Hydra\Broadcast\Hub\Server;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\Tests\Unit\WritesEnvironment;
use Hydra\Core\Environment;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The whole path against a real server: publish through the wired-up
 * broadcaster, the hub's own Redis subscription hears it, and a browser
 * socket gets the frame. A missing Redis is a skip on a bare checkout and a
 * failure in CI.
 */
#[CoversClass(RedisSubscriber::class)]
#[CoversClass(RedisHubStatus::class)]
#[CoversClass(Server::class)]
final class HubRedisTest extends TestCase
{
    use WritesEnvironment;

    private FakeContainer $container;
    private CapturingLogger $logger;
    private Server $server;

    /** @var resource|null */
    private $browser = null;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->unavailable('ext-redis is not installed.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: '6379';
        $probe = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 1.0);

        if ($probe === false) {
            $this->unavailable("No Redis to test against: {$error}.");
        }

        fclose($probe);

        $this->logger = new CapturingLogger;
        $this->container = new FakeContainer([
            Environment::class => $this->environment([
                'BROADCAST_DRIVER' => 'redis',
                'CACHE_STORE' => 'array',
                'REDIS_HOST' => $host,
                'REDIS_PORT' => $port,
                'REDIS_PREFIX' => 'hydra-test-' . bin2hex(random_bytes(4)) . ':',
                'SSE_LISTEN' => '127.0.0.1:1',
            ]),
            Signer::class => Signer::fromHex(str_repeat('ab', 32)),
            LoggerInterface::class => $this->logger,
        ]);
        (new BroadcastServiceProvider)->register($this->container);

        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($listener);
        $address = (string) stream_socket_get_name($listener, false);
        $this->server = $this->container->get(HubFactory::class)->server($listener);

        $this->until(fn (): bool => $this->container->get(HubStatus::class)->read()?->subscribed === true, 'The hub never subscribed.');

        $token = $this->container->get(StreamToken::class)->mint(1, ['module.users'], 60);
        $browser = stream_socket_client("tcp://{$address}", $errno, $error, 1.0);
        $this->assertNotFalse($browser);
        stream_set_blocking($browser, false);
        fwrite($browser, "GET /stream?token={$token} HTTP/1.1\r\n\r\n");
        $this->browser = $browser;

        $this->until(fn (): bool => str_contains($this->read(), ': connected'), 'The stream never opened.');
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }

        if (is_resource($this->browser)) {
            fclose($this->browser);
        }
    }

    public function test_a_publish_reaches_a_browser_through_the_hub(): void
    {
        $this->container->get(BroadcasterInterface::class)->publish('module.users', 'changed', ['id' => 7]);
        $this->container->get(BroadcasterInterface::class)->publish('module.files', 'changed');

        $got = '';
        $this->until(function () use (&$got): bool {
            $got .= $this->read();

            return str_contains($got, "\n\n");
        }, 'Nothing arrived.');

        $this->assertSame("event: module.users\ndata: {\"event\":\"changed\",\"data\":{\"id\":7}}\n\n", $got);
        $this->assertSame([], array_filter($this->logger->messages(), static fn (string $m): bool => str_starts_with($m, 'Could not')));
    }

    public function test_the_status_is_on_redis_and_cleared_at_stop(): void
    {
        $status = $this->container->get(HubStatus::class);

        $report = $status->read();
        $this->assertNotNull($report);
        $this->assertSame(getmypid(), $report->pid);
        $this->assertTrue($report->subscribed);

        $this->server->stop();

        $this->assertNull($status->read());
    }

    private function until(callable $done, string $why): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->server->tick(0.02);

            if ($done()) {
                return;
            }
        }

        throw new RuntimeException($why);
    }

    private function read(): string
    {
        return is_resource($this->browser) ? (string) fread($this->browser, 65536) : '';
    }

    private function unavailable(string $why): never
    {
        if (getenv('REDIS_REQUIRED') !== false) {
            $this->fail($why . ' REDIS_REQUIRED is set, so this is a failure rather than a skip.');
        }

        $this->markTestSkipped($why);
    }
}
