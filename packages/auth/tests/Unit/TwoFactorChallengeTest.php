<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Hydra\Auth\Events\TwoFactorChallenged;
use Hydra\Auth\Events\TwoFactorFailed;
use Hydra\Auth\RecoveryCodes;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\Testing\ArrayTwoFactorStore;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeHasher;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Totp;
use Hydra\Auth\TwoFactorChallenge;
use Hydra\Cache\ArrayStore;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Event\Testing\FakeDispatcher;
use Hydra\Http\ClientIpResolver;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\Throttle\Exceptions\TooManyRequestsException;
use Hydra\Throttle\RateLimitPolicy;
use Hydra\Throttle\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Driven against the real session, guard, Totp and rate limiter, with the
 * clock frozen so a code can be computed for the moment it is checked.
 */
#[CoversClass(TwoFactorChallenge::class)]
final class TwoFactorChallengeTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private FrozenClock $clock;

    private ArraySessionStore $session;

    private ArrayUserProvider $provider;

    private SessionGuard $guard;

    private TwoFactorStoreInterface $store;

    private Totp $totp;

    private RecoveryCodes $recoveryCodes;

    private FakeDispatcher $events;

    private FakeUser $ada;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('@1111111111');
        $this->session = new ArraySessionStore;
        $this->session->start();
        $this->provider = new ArrayUserProvider;
        $this->ada = new FakeUser(1);
        $this->provider->add('ada', $this->ada);
        $this->events = new FakeDispatcher;
        $this->guard = new SessionGuard($this->session, $this->provider, new FakeHasher);
        $this->store = new ArrayTwoFactorStore;
        $this->totp = new Totp($this->clock);
        $this->recoveryCodes = new RecoveryCodes(new FakeHasher);

        ['codes' => $this->codes, 'hashes' => $hashes] = $this->recoveryCodes->generate(3);
        $this->store->enable($this->ada, self::SECRET, $hashes);
    }

    private function challenge(?RateLimitPolicy $policy = null): TwoFactorChallenge
    {
        return new TwoFactorChallenge(
            $this->session,
            $this->provider,
            $this->guard,
            $this->store,
            $this->totp,
            $this->recoveryCodes,
            new RateLimiter(ArrayStore::withClock($this->clock), new ClientIpResolver),
            $this->clock,
            $this->events,
            $policy,
        );
    }

    public function test_only_an_account_with_a_secret_needs_a_code(): void
    {
        $this->assertTrue($this->challenge()->required($this->ada));
        $this->assertFalse($this->challenge()->required(new FakeUser(2)));
    }

    public function test_begin_holds_the_user_without_signing_them_in(): void
    {
        $before = $this->session->id();
        $challenge = $this->challenge();

        $challenge->begin($this->ada);

        $this->assertSame($this->ada, $challenge->pending());
        $this->assertFalse($this->guard->check());
        $this->assertNotSame($before, $this->session->id());
        $this->assertSame([TwoFactorChallenged::class], $this->events->types());
    }

    public function test_the_right_code_signs_the_user_in_and_ends_the_challenge(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertTrue($challenge->verify($this->totp->code(self::SECRET)));

        $this->assertSame(1, $this->guard->id());
        $this->assertNull($challenge->pending());
    }

    public function test_a_wrong_code_signs_nobody_in_and_the_challenge_stays(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertFalse($challenge->verify('000000'));

        $this->assertFalse($this->guard->check());
        $this->assertSame($this->ada, $challenge->pending());
        $this->assertSame([TwoFactorChallenged::class, TwoFactorFailed::class], $this->events->types());
    }

    public function test_a_code_used_once_is_refused_the_second_time(): void
    {
        $code = $this->totp->code(self::SECRET);
        $challenge = $this->challenge();
        $challenge->begin($this->ada);
        $challenge->verify($code);
        $this->guard->logout();

        $challenge->begin($this->ada);

        $this->assertFalse($challenge->verify($code));
        $this->assertFalse($this->guard->check());
    }

    public function test_nothing_pending_means_no_code_is_checked(): void
    {
        $challenge = $this->challenge();

        $this->assertFalse($challenge->verify($this->totp->code(self::SECRET)));
        $this->assertFalse($challenge->recover($this->codes[0]));
        $this->assertFalse($this->guard->check());
        $this->assertSame([], $this->events->types());
    }

    public function test_the_wait_runs_out(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->clock->set('@' . (1111111111 + TwoFactorChallenge::TTL - 1));
        $this->assertSame($this->ada, $challenge->pending());

        $this->clock->set('@' . (1111111111 + TwoFactorChallenge::TTL));
        $this->assertNull($challenge->pending());
        $this->assertFalse($challenge->verify($this->totp->code(self::SECRET)));

        $this->clock->set('@1111111111');
        $this->assertNull($challenge->pending(), 'an expired challenge is forgotten, not paused');
    }

    public function test_a_pending_marker_that_is_not_an_id_is_nothing_pending(): void
    {
        $this->session->set('_2fa_user', ['1']);
        $this->session->set('_2fa_expires', 1111111111 + 60);

        $this->assertNull($this->challenge()->pending());
    }

    public function test_a_pending_marker_without_an_expiry_is_forgotten(): void
    {
        $this->session->set('_2fa_user', 1);

        $this->assertNull($this->challenge()->pending());
        $this->assertFalse($this->session->has('_2fa_user'));
    }

    public function test_a_user_deleted_while_pending_is_nobody(): void
    {
        $challenge = $this->challenge();
        $challenge->begin(new FakeUser(99));

        $this->assertNull($challenge->pending());
    }

    public function test_cancel_forgets_the_pending_user_and_leaves_nothing_behind(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $challenge->cancel();

        $this->assertNull($challenge->pending());
        $this->assertSame([], $this->session->all());
    }

    public function test_signing_in_leaves_no_trace_of_the_challenge_in_the_session(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $challenge->verify($this->totp->code(self::SECRET));

        $this->assertSame(['_auth_id', '_auth_digest'], array_keys($this->session->all()));
    }

    public function test_without_a_dispatcher_every_path_still_works(): void
    {
        $challenge = new TwoFactorChallenge(
            $this->session,
            $this->provider,
            $this->guard,
            $this->store,
            $this->totp,
            $this->recoveryCodes,
            new RateLimiter(ArrayStore::withClock($this->clock), new ClientIpResolver),
            $this->clock,
        );
        $challenge->begin($this->ada);

        $this->assertFalse($challenge->verify('000000'));
        $this->assertTrue($challenge->recover($this->codes[0]));
        $this->assertSame(1, $this->guard->id());
    }

    public function test_an_account_whose_second_factor_went_away_cannot_finish(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);
        $this->store->disable($this->ada);

        $this->assertFalse($challenge->verify($this->totp->code(self::SECRET)));
        $this->assertFalse($this->guard->check());
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertTrue($challenge->recover(strtoupper($this->codes[1])));

        $this->assertSame(1, $this->guard->id());
        $this->assertCount(2, $this->store->recoveryHashes($this->ada));
        $this->assertSame(2, $this->events->first(RecoveryCodeUsed::class)->remaining);
        $this->assertSame([TwoFactorChallenged::class, RecoveryCodeUsed::class], $this->events->types());

        $this->guard->logout();
        $challenge->begin($this->ada);

        $this->assertFalse($challenge->recover($this->codes[1]));
        $this->assertFalse($this->guard->check());
    }

    public function test_the_last_recovery_code_still_works(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->recoveryCodes->generate(1);
        $this->store->replaceRecoveryHashes($this->ada, $hashes);
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertTrue($challenge->recover($codes[0]));
        $this->assertSame(0, $this->events->first(RecoveryCodeUsed::class)->remaining);
    }

    public function test_a_wrong_recovery_code_is_a_failure(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertFalse($challenge->recover('22222-22222'));

        $this->assertFalse($this->guard->check());
        $this->assertCount(3, $this->store->recoveryHashes($this->ada));
        $this->assertSame([TwoFactorChallenged::class, TwoFactorFailed::class], $this->events->types());
    }

    public function test_a_recovery_code_spent_elsewhere_in_the_meantime_is_refused(): void
    {
        // The store's compare-and-set is what decides, not the list read a
        // moment before: another request spent this code first.
        $inner = $this->store;
        $this->store = new class ($inner) implements TwoFactorStoreInterface {
            public function __construct(private readonly TwoFactorStoreInterface $inner) {}

            public function secret(AuthenticatableInterface $user): ?string { return $this->inner->secret($user); }

            public function claimStep(AuthenticatableInterface $user, int $step): bool { return $this->inner->claimStep($user, $step); }

            public function recoveryHashes(AuthenticatableInterface $user): array { return $this->inner->recoveryHashes($user); }

            public function spendRecoveryHash(AuthenticatableInterface $user, string $hash): bool { return false; }

            public function enable(AuthenticatableInterface $user, string $secret, array $recoveryHashes): void {}

            public function replaceRecoveryHashes(AuthenticatableInterface $user, array $recoveryHashes): void {}

            public function disable(AuthenticatableInterface $user): void {}
        };
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $this->assertFalse($challenge->recover($this->codes[0]));
        $this->assertFalse($this->guard->check());
    }

    public function test_the_budget_is_per_account_and_refuses_with_a_429(): void
    {
        $challenge = $this->challenge(new RateLimitPolicy('two-factor', 2, 300));
        $challenge->begin($this->ada);

        $challenge->verify('000000');
        $challenge->recover('22222-22222');

        try {
            $challenge->verify($this->totp->code(self::SECRET));
            $this->fail('the third try went through');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(300, $e->retryAfter());
        }

        $this->assertFalse($this->guard->check(), 'even the right code, once the budget is spent');

        // A new session, as a new address or browser would bring, is the same account.
        $this->session->clear();
        $challenge->begin($this->ada);
        $this->expectException(TooManyRequestsException::class);
        $challenge->verify($this->totp->code(self::SECRET));
    }

    public function test_the_default_budget_is_five_tries_in_the_window(): void
    {
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($challenge->verify('000000'));
        }

        $this->expectException(TooManyRequestsException::class);
        $challenge->verify('000000');
    }

    public function test_signing_in_announces_logged_in_through_the_guard(): void
    {
        $guard = new SessionGuard($this->session, $this->provider, new FakeHasher, $this->events);
        $this->guard = $guard;
        $challenge = $this->challenge();
        $challenge->begin($this->ada);

        $challenge->verify($this->totp->code(self::SECRET));

        $this->assertSame([TwoFactorChallenged::class, LoggedIn::class], $this->events->types());
    }
}
