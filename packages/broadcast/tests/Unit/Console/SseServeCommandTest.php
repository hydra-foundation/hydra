<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Console;

use Closure;
use Hydra\Broadcast\Console\SseServeCommand;
use Hydra\Broadcast\Hub\HubConfig;
use Hydra\Broadcast\Hub\HubFactory;
use Hydra\Broadcast\Hub\Poll\Poller;
use Hydra\Broadcast\Hub\Poll\SelectPoller;
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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

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

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_it_serves_until_told_to_stop_then_cleans_up(): void
    {
        $logger = new CapturingLogger;
        $status = new FakeHubStatus;
        $output = new FakeOutput;
        $port = self::freePort();

        $code = self::command(new HubConfig('127.0.0.1', $port), $logger, $status, stopOnFirstRound: true)->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $code);
        $this->assertStringStartsWith("Listening on 127.0.0.1:{$port}.", $output->linesOfKind('note')[0]);
        $this->assertSame([
            "The SSE hub is listening on 127.0.0.1:{$port} (select, up to 896 streams).",
            'The SSE hub stopped.',
        ], $logger->messages());
        $this->assertNull($status->read());
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_the_start_line_names_the_poller_and_the_cap_it_settled_on(): void
    {
        $logger = new CapturingLogger;
        $port = self::freePort();

        self::command(new HubConfig('127.0.0.1', $port), $logger, new FakeHubStatus, stopOnFirstRound: true, poller: self::unlimited(...))
            ->execute(new ArrayInput, new FakeOutput);

        $this->assertSame("The SSE hub is listening on 127.0.0.1:{$port} (epoll, up to 1000 streams).", $logger->messages()[0]);
    }

    public function test_a_cap_the_poller_cannot_watch_is_refused_before_the_port_is_bound(): void
    {
        $logger = new CapturingLogger;
        $output = new FakeOutput;
        $port = self::freePort();

        $code = self::command(new HubConfig('127.0.0.1', $port, maxConnections: 1000), $logger, new FakeHubStatus)->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertStringStartsWith('SSE_MAX_CONNECTIONS=1000 is more than this PHP can watch with stream_select (896).', $output->linesOfKind('error')[0]);
        $this->assertSame([], $logger->messages());

        $rebound = stream_socket_server("tcp://127.0.0.1:{$port}");
        $this->assertNotFalse($rebound, 'the port was left free');
        fclose($rebound);
    }

    public function test_the_factory_waits_through_io_poll_where_php_has_it(): void
    {
        $expected = class_exists(\Io\Poll\Context::class) ? 'epoll' : 'select';

        $this->assertSame($expected, HubFactory::bestPoller()->name());
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

    /**
     * With $stopOnFirstRound, the subscriber sends the process SIGTERM on the
     * loop's first round, so the command's own signal handling ends the run.
     *
     * @param (Closure(): Poller)|null $poller select unless given, so the cap is the same on every PHP
     */
    private static function command(
        HubConfig $config,
        CapturingLogger $logger,
        FakeHubStatus $status,
        bool $stopOnFirstRound = false,
        ?Closure $poller = null,
    ): SseServeCommand {
        $clock = new FrozenClock;
        $factory = new HubFactory(
            static fn () => $stopOnFirstRound ? new TerminatingSubscriber : new HandFedSubscriber,
            new StreamToken(Signer::fromHex(str_repeat('ab', 32)), $clock),
            $config,
            $clock,
            $logger,
            $status,
            $poller ?? static fn (): Poller => new SelectPoller,
        );

        return new SseServeCommand($factory, $logger);
    }

    private static function unlimited(): Poller
    {
        return new class implements Poller {
            private SelectPoller $inner;

            public function __construct()
            {
                $this->inner = new SelectPoller;
            }

            public function watch(mixed $socket): void
            {
                $this->inner->watch($socket);
            }

            public function forget(mixed $socket): void
            {
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
                return 'epoll';
            }

            public function capacity(): ?int
            {
                return null;
            }
        };
    }
}

/** Asks the process to stop, the way `docker stop` does, the first time the hub looks at it. */
final class TerminatingSubscriber extends HandFedSubscriber
{
    private bool $sent = false;

    public function maintain(): void
    {
        if (!$this->sent) {
            $this->sent = true;
            posix_kill(getmypid(), SIGTERM);
        }
    }
}
