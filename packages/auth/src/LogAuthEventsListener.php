<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\EmailVerified;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Auth\Events\PasswordReset;
use Hydra\Auth\Events\PasswordResetLinkSent;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Hydra\Auth\Events\TwoFactorChallenged;
use Hydra\Auth\Events\TwoFactorFailed;
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

    public function onPasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->logger->info('auth.reset_link_sent', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->logger->notice('auth.password_reset', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onEmailVerified(EmailVerified $event): void
    {
        $this->logger->info('auth.email_verified', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onTwoFactorChallenged(TwoFactorChallenged $event): void
    {
        $this->logger->info('auth.two_factor_challenged', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onTwoFactorFailed(TwoFactorFailed $event): void
    {
        $this->logger->warning('auth.two_factor_failed', ['user' => $event->user->getAuthIdentifier()]);
    }

    public function onRecoveryCodeUsed(RecoveryCodeUsed $event): void
    {
        $this->logger->notice('auth.recovery_code_used', [
            'user' => $event->user->getAuthIdentifier(),
            'remaining' => $event->remaining,
        ]);
    }
}
