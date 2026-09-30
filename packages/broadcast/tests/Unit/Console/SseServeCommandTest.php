<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Console;

use Hydra\Broadcast\Console\SseServeCommand;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\HubFactory;
use Hydra\Broadcast\StreamToken;
use Hydra\Broadcast\Tests\Unit\Hub\HandFedSubscriber;
use Hydra\Broadcast\Testing\FakeHubStatus;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SseServeCommand::class)]
#[CoversClass(HubFactory::class)]
final class SseServeCommandTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        yield 'sse:serve' => self::command(new HubConfig, new CapturingLogger, new FakeHubStatus);
    }

    public function test_a_port_it_cannot_bind_fails_with_the_reason(): void
    {
        $port = self::freePort();
        $taken = stream_socket_server("tcp://127.0.0.1:{$port}");
        $this->assertNotFalse($taken);

        $output = new FakeOutput;
        $code = self::command(new HubConfig('127.0.0.1', $port), new CapturingLogger, new FakeHubStatus)->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertStringStartsWith("Could not listen on 127.0.0.1:{$port}: ", $output->linesOfKind('error')[0]);
        fclose($taken);
    }

    public function test_it_serves_until_halted_then_cleans_up(): void
    {
        $logger = new CapturingLogger;
        $status = new FakeHubStatus;
        $output = new FakeOutput;

        $port = self::freePort();

        $code = self::command(new HubConfig('127.0.0.1', $port), $logger, $status, halted: true)->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $code);
        $this->assertStringStartsWith("Listening on 127.0.0.1:{$port}.", $output->linesOfKind('note')[0]);
        $this->assertSame(["The SSE hub is listening on 127.0.0.1:{$port}.", 'The SSE hub stopped.'], $logger->messages());
        $this->assertNull($status->read());
    }

    /** A port nothing is bound to, a moment ago. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** With $halted, the server is built already asked to stop, so run() cleans up and returns. */
    private static function command(HubConfig $config, CapturingLogger $logger, FakeHubStatus $status, bool $halted = false): SseServeCommand
    {
        $clock = new FrozenClock;
        $factory = new HubFactory(
            static fn () => new HandFedSubscriber,
            new StreamToken(Signer::fromHex(str_repeat('ab', 32)), $clock),
            $config,
            $clock,
            $logger,
            $status,
        );

        return new SseServeCommand($config, static function ($listener) use ($factory, $halted) {
            $server = $factory->server($listener);

            if ($halted) {
                $server->halt();
            }

            return $server;
        }, $logger);
    }
}
