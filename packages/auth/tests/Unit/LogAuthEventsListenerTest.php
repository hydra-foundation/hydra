<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Auth\LogAuthEventsListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The listener is driven with hand-built events and a fake logger — it asserts
 * only the audit-trail contract: the right level, message, and context, and that
 * no password is present (the events never carry one).
 */
final class LogAuthEventsListenerTest extends TestCase
{
    private RecordingLogger $logger;
    private LogAuthEventsListener $listener;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger;
        $this->listener = new LogAuthEventsListener($this->logger);
    }

    public function test_attempting_logs_at_debug_with_the_username(): void
    {
        $this->listener->onAttempting(new Attempting('ada'));

        $this->assertSame(['debug', 'auth.attempting', ['username' => 'ada']], $this->logger->records[0]);
    }

    public function test_failed_logs_at_warning_with_the_username(): void
    {
        $this->listener->onFailed(new LoginFailed('ada'));

        $this->assertSame(['warning', 'auth.login_failed', ['username' => 'ada']], $this->logger->records[0]);
    }

    public function test_logged_in_logs_at_info_with_the_identifier(): void
    {
        $this->listener->onLoggedIn(new LoggedIn(new StubUser(42)));

        $this->assertSame(['info', 'auth.login', ['user' => 42]], $this->logger->records[0]);
    }

    public function test_logged_out_logs_at_info_with_the_prior_identifier(): void
    {
        $this->listener->onLoggedOut(new LoggedOut(42));

        $this->assertSame(['info', 'auth.logout', ['user' => 42]], $this->logger->records[0]);
    }
}

/** Captures every log call as [level, message, context] without writing anywhere. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}

final class StubUser implements AuthenticatableInterface
{
    public function __construct(private readonly int|string $id) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }
}
