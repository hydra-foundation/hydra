<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Integration;

use Hydra\Broadcast\BroadcastServiceProvider;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Drivers\PhpRedisChannel;
use Hydra\Broadcast\Drivers\RedisBroadcaster;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Tests\Unit\WritesEnvironment;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The wire format against a real server: what a pattern subscriber, which is
 * what the hub will be, receives from a publish through the wired-up driver.
 *
 * The subscriber is a raw socket speaking RESP rather than phpredis, whose
 * subscribe() blocks until the callback says stop. A missing Redis is a skip
 * on a bare checkout and a failure in CI.
 */
#[CoversClass(RedisBroadcaster::class)]
#[CoversClass(PhpRedisChannel::class)]
#[CoversClass(BroadcastServiceProvider::class)]
final class RedisBroadcastingTest extends TestCase
{
    use WritesEnvironment;

    /** @var resource|null */
    private $subscriber = null;

    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->unavailable('ext-redis is not installed.');
        }

        $this->prefix = 'hydra-test-' . bin2hex(random_bytes(4)) . ':';
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', self::host(), self::port()), $errno, $error, 1.0);

        if ($socket === false) {
            $this->unavailable("No Redis to test against: {$error}.");
        }

        stream_set_timeout($socket, 2);
        $this->subscriber = $socket;

        $this->command('PSUBSCRIBE', $this->prefix . 'broadcast.*');
        $this->assertSame(['psubscribe', $this->prefix . 'broadcast.*', 1], $this->reply());
    }

    protected function tearDown(): void
    {
        if ($this->subscriber !== null) {
            fclose($this->subscriber);
        }
    }

    public function test_a_subscriber_receives_exactly_the_published_envelope_on_the_topic_channel(): void
    {
        $logger = new CapturingLogger;
        $broadcaster = $this->broadcaster($logger);

        $broadcaster->publish('module.users', 'changed', ['id' => 7, 'name' => 'Zoë']);

        [$kind, $pattern, $channel, $payload] = $this->reply();

        $this->assertSame('pmessage', $kind);
        $this->assertSame($this->prefix . 'broadcast.*', $pattern);
        $this->assertSame($this->prefix . 'broadcast.module.users', $channel);
        $this->assertIsString($payload);

        $envelope = Envelope::fromJson($payload);
        $this->assertNotNull($envelope);
        $this->assertSame(['module.users', 'changed', ['id' => 7, 'name' => 'Zoë']], [$envelope->topic, $envelope->event, $envelope->data]);
        $this->assertSame([], $logger->records());
    }

    public function test_one_connection_carries_several_publishes_in_order(): void
    {
        $broadcaster = $this->broadcaster(new CapturingLogger);

        $broadcaster->publish('a', 'first');
        $broadcaster->publish('b', 'second');

        $this->assertSame($this->prefix . 'broadcast.a', $this->reply()[2]);
        $this->assertSame($this->prefix . 'broadcast.b', $this->reply()[2]);
    }

    private function broadcaster(LoggerInterface $logger): BroadcasterInterface
    {
        $container = new FakeContainer([
            Environment::class => $this->environment([
                'BROADCAST_DRIVER' => 'redis',
                'CACHE_STORE' => 'array',
                'REDIS_HOST' => self::host(),
                'REDIS_PORT' => (string) self::port(),
                'REDIS_PREFIX' => $this->prefix,
            ]),
            LoggerInterface::class => $logger,
        ]);
        (new BroadcastServiceProvider)->register($container);

        return $container->get(BroadcasterInterface::class);
    }

    private function command(string ...$args): void
    {
        $frame = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $frame .= '$' . strlen($arg) . "\r\n{$arg}\r\n";
        }

        fwrite($this->subscriberSocket(), $frame);
    }

    /** One RESP reply: arrays, bulk strings and integers are all a subscriber sees. */
    private function reply(): mixed
    {
        $line = fgets($this->subscriberSocket());

        if ($line === false) {
            throw new RuntimeException('Redis sent nothing within the timeout.');
        }

        $body = substr($line, 1, -2);

        return match ($line[0]) {
            '*' => array_map(fn () => $this->reply(), array_fill(0, (int) $body, null)),
            '$' => substr((string) stream_get_contents($this->subscriberSocket(), (int) $body + 2), 0, -2),
            ':' => (int) $body,
            default => throw new RuntimeException("Unexpected reply: {$line}"),
        };
    }

    /** @return resource */
    private function subscriberSocket()
    {
        return $this->subscriber ?? throw new RuntimeException('No subscriber.');
    }

    private static function host(): string
    {
        return getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    private static function port(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    private function unavailable(string $why): never
    {
        if (getenv('REDIS_REQUIRED') !== false) {
            $this->fail($why . ' REDIS_REQUIRED is set, so this is a failure rather than a skip.');
        }

        $this->markTestSkipped($why);
    }
}
