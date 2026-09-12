<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The guard is driven against the REAL collaborators, the in-memory
 * ArraySessionStore and the real NativeHasher (at the cheapest cost), with only
 * the app-supplied user provider faked. So these prove the actual session
 * read/write and password-verify paths, not a mock of them.
 */
final class SessionGuardTest extends TestCase
{
    private const PASSWORD = 'correct horse';

    private ArrayUserProvider $provider;
    private NativeHasher $hasher;
    private ArraySessionStore $session;

    protected function setUp(): void
    {
        $this->hasher = new NativeHasher(new AuthConfig(hashCost: 4));
        $this->provider = new ArrayUserProvider;
        $this->session = new ArraySessionStore;
        $this->session->start();
        // One known user, password stored as a real hash.
        $this->provider->add('ada', new FakeUser(1, $this->hasher->hash(self::PASSWORD)));
    }

    private function guard(?ArraySessionStore $session = null): SessionGuard
    {
        return new SessionGuard($session ?? $this->session, $this->provider, $this->hasher);
    }

    private function guardWithEvents(RecordingDispatcher $events): SessionGuard
    {
        return new SessionGuard($this->session, $this->provider, $this->hasher, $events);
    }

    public function test_starts_unauthenticated(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function test_login_authenticates_and_exposes_the_user(): void
    {
        $guard = $this->guard();
        $user = $this->provider->byUsername('ada');

        $guard->login($user);

        $this->assertTrue($guard->check());
        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $guard->id());
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $guard = $this->guard();
        $before = $this->session->id();

        $guard->login($this->provider->byUsername('ada'));

        // Fixation defense: the authenticated session must not reuse the
        // pre-login id, but the stored marker must survive the rotation.
        $this->assertNotSame($before, $this->session->id());
        $this->assertSame(1, $guard->id());
    }

    public function test_attempt_with_correct_credentials_logs_in(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->attempt('ada', self::PASSWORD));
        $this->assertTrue($guard->check());
        $this->assertSame(1, $guard->id());
    }

    public function test_attempt_with_wrong_password_fails_and_does_not_log_in(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->attempt('ada', 'wrong'));
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
    }

    public function test_attempt_with_unknown_user_fails(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->attempt('nobody', self::PASSWORD));
        $this->assertFalse($guard->check());
        // The missing-user branch still burns one hash (timing defense) but no
        // login happened; the provider was consulted exactly once.
        $this->assertSame(1, $this->provider->byUsernameCalls);
    }

    public function test_attempt_against_a_user_with_no_password_fails(): void
    {
        // A passwordless/disabled account must never authenticate, and (per the
        // timing defense) is routed through the same one-hash burn as a missing
        // user rather than short-circuiting.
        $this->provider->add('ghost', new FakeUser(2, ''));
        $guard = $this->guard();

        $this->assertFalse($guard->attempt('ghost', 'anything'));
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
    }

    public function test_login_then_logout_then_check_within_one_request(): void
    {
        $guard = $this->guard();

        $guard->login($this->provider->byUsername('ada'));
        $this->assertTrue($guard->check());

        $guard->logout();

        // The primed cache must be cleared by logout, not left asserting a user.
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function test_a_non_scalar_session_value_is_not_a_login(): void
    {
        // The session surface is untyped; a non-scalar under the reserved key
        // ('_auth_id', mirrored here) must not be treated as an identifier.
        $this->session->set('_auth_id', ['not', 'a', 'scalar']);
        $guard = $this->guard();

        $this->assertNull($guard->id());
        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
    }

    public function test_logout_clears_authentication_and_regenerates_id(): void
    {
        $guard = $this->guard();
        $guard->login($this->provider->byUsername('ada'));
        $idBefore = $this->session->id();

        $guard->logout();

        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
        $this->assertNull($guard->user());
        $this->assertNotSame($idBefore, $this->session->id());
    }

    public function test_logout_flushes_all_session_data(): void
    {
        // Regression: logout once removed only the '_auth_id' marker, so data a
        // controller stashed during the authenticated session (cart, CSRF token,
        // profile fragments) survived into the next session, possibly someone
        // else's, on a shared machine. OWASP: invalidate the WHOLE session.
        $guard = $this->guard();
        $guard->login($this->provider->byUsername('ada'));
        $this->session->set('cart', ['sku-42']);
        $this->session->set('csrf_token', 'secret');
        $idBefore = $this->session->id();

        $guard->logout();

        // Every stored value is gone, not just the auth marker...
        $this->assertSame([], $this->session->all());
        $this->assertFalse($this->session->has('cart'));
        $this->assertFalse($this->session->has('csrf_token'));

        // ...while the existing logout guarantees still hold: fresh id, event
        // behaviour covered below in the dispatcher tests.
        $this->assertNotSame($idBefore, $this->session->id());
        $this->assertFalse($guard->check());
    }

    public function test_logout_flush_still_regenerates_and_dispatches_logged_out(): void
    {
        // The full flush must not disturb the rest of logout: the id still
        // rotates (fixation defense) and LoggedOut still carries the prior id,
        // which is captured before the session is emptied.
        $events = new RecordingDispatcher;
        $guard = $this->guardWithEvents($events);
        $guard->login($this->provider->byUsername('ada'));
        $this->session->set('leftover', 'value');
        $idBefore = $this->session->id();
        $events->reset();

        $guard->logout();

        $this->assertSame([], $this->session->all());
        $this->assertNotSame($idBefore, $this->session->id());
        $this->assertSame([LoggedOut::class], $events->types());
        $this->assertSame(1, $events->first(LoggedOut::class)->userId);
    }

    public function test_authentication_persists_to_a_later_request(): void
    {
        // Log in on one guard, then resolve on a fresh guard over the SAME
        // store, i.e. the next request. The user is restored from the session
        // id via the provider's byIdentifier lookup.
        $this->guard()->login($this->provider->byUsername('ada'));

        $next = $this->guard();
        $this->assertTrue($next->check());
        $this->assertSame(1, $next->id());
        $this->assertSame('ada', $this->userName($next->user()));
    }

    public function test_user_is_resolved_at_most_once_per_request(): void
    {
        // Fresh guard with an id already in the session (a returning request).
        $this->guard()->login($this->provider->byUsername('ada'));
        $this->provider->byIdentifierCalls = 0;

        $next = $this->guard();
        $next->user();
        $next->user();
        $next->check();

        // Three reads, one provider lookup, so the per-request cache holds.
        $this->assertSame(1, $this->provider->byIdentifierCalls);
    }

    public function test_login_primes_the_cache_without_a_provider_lookup(): void
    {
        $guard = $this->guard();
        $this->provider->byIdentifierCalls = 0;

        $guard->login($this->provider->byUsername('ada'));
        $guard->user();
        $guard->check();

        // login() handed the guard the user directly; no byIdentifier needed.
        $this->assertSame(0, $this->provider->byIdentifierCalls);
    }

    public function test_no_dispatcher_means_no_events_and_unchanged_behaviour(): void
    {
        // The default guard() has no dispatcher: this is just a restatement that
        // the happy path still works with events entirely absent: the other
        // dozen tests above all run on this dispatcher-less guard.
        $guard = $this->guard();

        $this->assertTrue($guard->attempt('ada', self::PASSWORD));
        $this->assertTrue($guard->check());
    }

    public function test_attempt_dispatches_attempting_then_logged_in_on_success(): void
    {
        $events = new RecordingDispatcher;

        $this->assertTrue($this->guardWithEvents($events)->attempt('ada', self::PASSWORD));

        // Attempting fires before the lookup, LoggedIn after the session is set.
        $this->assertSame([Attempting::class, LoggedIn::class], $events->types());
        $this->assertSame('ada', $events->first(Attempting::class)->username);
        $this->assertSame(1, $events->first(LoggedIn::class)->user->getAuthIdentifier());
    }

    public function test_attempt_dispatches_attempting_then_login_failed_on_wrong_password(): void
    {
        $events = new RecordingDispatcher;

        $this->assertFalse($this->guardWithEvents($events)->attempt('ada', 'wrong'));

        $this->assertSame([Attempting::class, LoginFailed::class], $events->types());
        $this->assertSame('ada', $events->first(LoginFailed::class)->username);
    }

    public function test_attempt_dispatches_login_failed_for_unknown_user(): void
    {
        $events = new RecordingDispatcher;

        $this->assertFalse($this->guardWithEvents($events)->attempt('nobody', self::PASSWORD));

        // A missing user is a failure like any other: same event, same shape, so
        // a listener can't tell "no such account" from "wrong password".
        $this->assertSame([Attempting::class, LoginFailed::class], $events->types());
        $this->assertSame('nobody', $events->first(LoginFailed::class)->username);
    }

    public function test_direct_login_dispatches_logged_in(): void
    {
        $events = new RecordingDispatcher;

        $this->guardWithEvents($events)->login($this->provider->byUsername('ada'));

        $this->assertSame([LoggedIn::class], $events->types());
        $this->assertSame(1, $events->first(LoggedIn::class)->user->getAuthIdentifier());
    }

    public function test_logout_dispatches_logged_out_with_the_prior_id(): void
    {
        $events = new RecordingDispatcher;
        $guard = $this->guardWithEvents($events);
        $guard->login($this->provider->byUsername('ada'));
        $events->reset();

        $guard->logout();

        // The id is captured before the marker is cleared, so LoggedOut still
        // knows who logged out.
        $this->assertSame([LoggedOut::class], $events->types());
        $this->assertSame(1, $events->first(LoggedOut::class)->userId);
    }

    public function test_a_stale_marker_for_a_deleted_user_is_removed_from_the_session(): void
    {
        // A session claiming user 999, an account the provider no longer knows
        // (deleted since login). Without cleanup the marker lives forever,
        // re-triggering a futile provider lookup on every request.
        $this->session->set('_auth_id', 999);
        $guard = $this->guard();

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertFalse($this->session->has('_auth_id'), 'the stale marker must be removed on a missed lookup');

        // The NEXT request (a fresh guard over the same store) is a plain
        // guest: no marker, so not even a provider lookup happens.
        $this->provider->byIdentifierCalls = 0;
        $next = $this->guard();
        $this->assertNull($next->id());
        $this->assertNull($next->user());
        $this->assertSame(0, $this->provider->byIdentifierCalls);
    }

    public function test_a_live_marker_is_left_untouched_by_resolution(): void
    {
        // The cleanup fires only on a MISSED lookup, so resolving a real user
        // must not disturb the marker.
        $this->guard()->login($this->provider->byUsername('ada'));

        $next = $this->guard();
        $this->assertTrue($next->check());
        $this->assertSame(1, $this->session->get('_auth_id'));
    }

    public function test_missing_user_and_wrong_password_attempts_cost_the_same_hash_work(): void
    {
        // The anti-enumeration defense: a miss (no such user) burns exactly one
        // hashing operation, the same count a wrong-password attempt spends on
        // its verify, so operation counts can't distinguish the two.
        $hasher = new CountingHasher($this->hasher);

        $missGuard = new SessionGuard($this->session, $this->provider, $hasher);
        $this->assertFalse($missGuard->attempt('nobody', 'whatever'));
        $this->assertSame(1, $hasher->operations(), 'a missing user must cost exactly one hashing operation');

        $hasher->reset();
        $wrongGuard = new SessionGuard($this->session, $this->provider, $hasher);
        $this->assertFalse($wrongGuard->attempt('ada', 'wrong'));
        $this->assertSame(1, $hasher->operations(), 'a wrong password must cost exactly one hashing operation');
    }

    public function test_repeated_missing_user_attempts_cost_identical_work(): void
    {
        // Regression: the dummy hash used to be computed lazily on first use,
        // so the FIRST miss paid hash+verify (two operations) while later
        // misses paid one, a measurable first-call timing skew.
        $hasher = new CountingHasher($this->hasher);
        $guard = new SessionGuard($this->session, $this->provider, $hasher);

        $guard->attempt('nobody', 'first');
        $first = $hasher->operations();

        $hasher->reset();
        $guard->attempt('nobody', 'second');
        $second = $hasher->operations();

        $this->assertSame(1, $first, 'the first miss must not pay extra setup work');
        $this->assertSame($first, $second, 'every miss must cost the same number of hashing operations');
    }

    private function userName(?AuthenticatableInterface $user): ?string
    {
        return $user instanceof FakeUser ? $user->username : null;
    }
}

/**
 * Wraps the real hasher and counts the expensive operations (hash + verify) so
 * the timing-defense tests can assert WORK EQUALITY by operation count instead
 * of flaky wall-clock measurement.
 */
final class CountingHasher implements \Hydra\Auth\Contracts\HasherInterface
{
    public int $hashCalls = 0;
    public int $verifyCalls = 0;

    public function __construct(private readonly \Hydra\Auth\Contracts\HasherInterface $inner) {}

    public function hash(string $plain): string
    {
        $this->hashCalls++;

        return $this->inner->hash($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        $this->verifyCalls++;

        return $this->inner->verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->inner->needsRehash($hash);
    }

    public function operations(): int
    {
        return $this->hashCalls + $this->verifyCalls;
    }

    public function reset(): void
    {
        $this->hashCalls = 0;
        $this->verifyCalls = 0;
    }
}

/**
 * A spy PSR-14 dispatcher: records every event it is handed, in order, and hands
 * it straight back per the interface contract. No listeners: the guard's job is
 * only to dispatch, and that is all this asserts.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function reset(): void
    {
        $this->events = [];
    }

    /** @return list<class-string> */
    public function types(): array
    {
        return array_map('get_class', $this->events);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function first(string $type): object
    {
        foreach ($this->events as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        throw new \RuntimeException("No {$type} was dispatched.");
    }
}

/** A minimal AuthenticatableInterface for the tests. */
final class FakeUser implements AuthenticatableInterface
{
    public ?string $username = null;

    public function __construct(private readonly int|string $id, private readonly string $hash) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->hash;
    }
}

/** In-memory user provider that records how often it is asked. */
final class ArrayUserProvider implements UserProviderInterface
{
    public int $byIdentifierCalls = 0;
    public int $byUsernameCalls = 0;

    /** @var array<string, FakeUser> */
    private array $byUsername = [];
    /** @var array<array-key, FakeUser> */
    private array $byId = [];

    public function add(string $username, FakeUser $user): void
    {
        $user->username = $username;
        $this->byUsername[$username] = $user;
        $this->byId[$user->getAuthIdentifier()] = $user;
    }

    public function byIdentifier(int|string $id): ?AuthenticatableInterface
    {
        $this->byIdentifierCalls++;

        return $this->byId[$id] ?? null;
    }

    public function byUsername(string $username): ?AuthenticatableInterface
    {
        $this->byUsernameCalls++;

        return $this->byUsername[$username] ?? null;
    }
}
