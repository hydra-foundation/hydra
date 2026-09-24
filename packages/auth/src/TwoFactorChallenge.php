<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Hydra\Auth\Events\TwoFactorChallenged;
use Hydra\Auth\Events\TwoFactorFailed;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Throttle\RateLimiter;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The second step of signing in. begin() records that a password was right;
 * nothing is signed in until verify() or recover() takes a code, and only then
 * is the guard asked to log the user in.
 *
 * The pending sign-in lives in the session and expires. The budget for codes
 * is counted per account, not per address: whoever has the password can come
 * from as many addresses as they like, and six digits is a million guesses.
 */
final class TwoFactorChallenge
{
    /** Five minutes to find the phone and type the code. */
    public const TTL = 300;

    private const USER_KEY = '_2fa_user';

    private const EXPIRES_KEY = '_2fa_expires';

    private readonly RateLimitPolicy $policy;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserProviderInterface $provider,
        private readonly GuardInterface $guard,
        private readonly TwoFactorStoreInterface $store,
        private readonly Totp $totp,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly RateLimiter $limiter,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $events = null,
        ?RateLimitPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new RateLimitPolicy('two-factor', 5, self::TTL);
    }

    /** Whether signing in as $user takes a code. */
    public function required(AuthenticatableInterface $user): bool
    {
        return $this->store->secret($user) !== null;
    }

    /** $user's password was right: hold the sign-in until a code arrives. */
    public function begin(AuthenticatableInterface $user): void
    {
        // A new id for the half-signed-in session, as login() gives the whole one.
        $this->session->regenerate();
        $this->session->set(self::USER_KEY, $user->getAuthIdentifier());
        $this->session->set(self::EXPIRES_KEY, $this->clock->now()->getTimestamp() + self::TTL);

        $this->events?->dispatch(new TwoFactorChallenged($user));
    }

    /** The user waiting on a code, or null when none is, or the wait ran out. */
    public function pending(): ?AuthenticatableInterface
    {
        $id = $this->session->get(self::USER_KEY);
        $expires = $this->session->get(self::EXPIRES_KEY);

        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        if (!is_int($expires) || $expires <= $this->clock->now()->getTimestamp()) {
            $this->cancel();

            return null;
        }

        return $this->provider->byIdentifier($id);
    }

    /**
     * Sign the pending user in if $code is their authenticator's, and not one
     * already used. False otherwise, and when nothing is pending.
     *
     * @throws TooManyRequestsException once the account's budget is spent
     */
    public function verify(string $code): bool
    {
        $user = $this->pending();

        if ($user === null) {
            return false;
        }

        $this->spend($user);

        $secret = $this->store->secret($user);
        $step = $secret === null ? null : $this->totp->verify($secret, $code);

        if ($step === null || !$this->store->claimStep($user, $step)) {
            return $this->fail($user);
        }

        return $this->complete($user);
    }

    /**
     * Sign the pending user in on one of their recovery codes, spending it.
     *
     * @throws TooManyRequestsException once the account's budget is spent
     */
    public function recover(string $code): bool
    {
        $user = $this->pending();

        if ($user === null) {
            return false;
        }

        $this->spend($user);

        $hashes = $this->store->recoveryHashes($user);
        $left = $this->recoveryCodes->redeem($code, $hashes);
        $spent = $left === null ? [] : array_values(array_diff($hashes, $left));

        if ($spent === [] || !$this->store->spendRecoveryHash($user, $spent[0])) {
            return $this->fail($user);
        }

        $this->events?->dispatch(new RecoveryCodeUsed($user, count($left ?? [])));

        return $this->complete($user);
    }

    /** Forget the pending sign-in: "use a different account", or a wait that ran out. */
    public function cancel(): void
    {
        $this->session->remove(self::USER_KEY);
        $this->session->remove(self::EXPIRES_KEY);
    }

    private function spend(AuthenticatableInterface $user): void
    {
        $status = $this->limiter->hit((string) $user->getAuthIdentifier(), $this->policy);

        if (!$status->allowed) {
            throw new TooManyRequestsException($status->retryAfter);
        }
    }

    private function fail(AuthenticatableInterface $user): bool
    {
        $this->events?->dispatch(new TwoFactorFailed($user));

        return false;
    }

    private function complete(AuthenticatableInterface $user): bool
    {
        $this->cancel();
        $this->guard->login($user);

        return true;
    }
}
