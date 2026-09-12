<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Psr\Log\LoggerInterface;

/**
 * An optional listener that writes a PSR-3 line for each auth lifecycle event.
 * A ready-made security audit trail every app tends to want the same way
 */
final class LogAuthEventsListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function onAttempting(Attempting $event): void
    {
        $this->logger->debug('auth.attempting', ['username' => $event->username]);
    }

    public function onFailed(LoginFailed $event): void
    {
        $this->logger->warning('auth.login_failed', ['username' => $event->username]);
    }

    public function onLoggedIn(LoggedIn $event): void
    {
        $this->logger->info('auth.login', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onLoggedOut(LoggedOut $event): void
    {
        $this->logger->info('auth.logout', ['user' => $event->userId]);
    }
}
