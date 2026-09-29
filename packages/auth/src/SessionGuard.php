<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Session\Contracts\SessionInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The session-backed {@see GuardInterface}: authentication state lives in the
 * session as a single stored identifier, so who the user is stays the app's to
 * define through a {@see UserProviderInterface}.
 */
final class SessionGuard implements GuardInterface
{
    /** Where the authenticated user's id lives in the session (framework-reserved). */
    private const SESSION_KEY = '_auth_id';

    /**
     * The digest of the password hash the session was signed in against. A
     * password changed anywhere else no longer matches it, which is what ends
     * every other session the account had.
     */
    private const DIGEST_KEY = '_auth_digest';

    /**
     * The id of this sign-in's record, when the app keeps them. The guard's own
     * id, not the session's: it survives regenerate(), and a list of sign-ins
     * can show it without showing anyone a credential. Deleting the record is
     * how a sign-in is revoked.
     */
    private const SIGN_IN_KEY = '_auth_sign_in';

    /** Per-request cache of the resolved user; $resolved distinguishes "null" from "not looked up yet". */
    private ?AuthenticatableInterface $cachedUser = null;
    private bool $resolved = false;

    /** The record user() found this sign-in's to be, when there is a store. */
    private ?SignIn $signIn = null;

    /**
     * $signIns and $clock are OPTIONAL, and come as a pair: bound, every login
     * is recorded and every request checks its record is still there. Left
     * out, the guard is exactly what it was before sign-ins were recorded.
     */
    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserProviderInterface $provider,
        private readonly HasherInterface $hasher,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?SignInStoreInterface $signIns = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($signIns !== null && $clock === null) {
            throw new LogicException(
                'SessionGuard keeps sign-in records with a clock; bind Psr\Clock\ClockInterface (ClockServiceProvider does).',
            );
        }
    }

    public function check(): bool
    {
        // Deliberately resolves the user rather than just checking the id: a
        // session pointing at a since-deleted account is not authenticated.
        return $this->user() !== null;
    }

    public function user(): ?AuthenticatableInterface
    {
        if ($this->resolved) {
            return $this->cachedUser;
        }

        $this->resolved = true;

        $id = $this->id();
        if ($id === null) {
            return $this->cachedUser = null;
        }

        $user = $this->provider->byIdentifier($id);

        if ($user === null) {
            // The marker points at a user the provider no longer knows, a
            // deleted account. Left in place it would repeat the futile lookup
            // on every request while the session went on asserting a login that
            // can never resolve. The id is deliberately NOT regenerated: this is
            // a read path, the session only DROPS its claim, and the next real
            // login() rotates the id as it always does.
            $this->session->remove(self::SESSION_KEY);
            $this->session->remove(self::SIGN_IN_KEY);

            return $this->cachedUser = null;
        }

        if (!$this->stampedFor($user)) {
            // Flushed rather than just unmarked, as logout() does: whoever holds
            // this session is, by assumption, not the account's owner, and what
            // it stored belongs to the account. A session from before the stamp
            // existed carries none and ends here too. Its record goes with it,
            // so a list of sign-ins shows only ones that still work.
            $this->revokeHeld();
            $this->session->clear();

            return $this->cachedUser = null;
        }

        if (!$this->recorded($user)) {
            // Revoked: flushed for the same reason as a changed password.
            $this->session->clear();

            return $this->cachedUser = null;
        }

        return $this->cachedUser = $user;
    }

    /**
     * The record of this sign-in, as user() found it, or null when there is
     * nobody signed in or the app keeps no records. What a middleware needs to
     * say when and from where it was last seen, and a list to mark "this one".
     */
    public function signIn(): ?SignIn
    {
        $this->user();

        return $this->signIn;
    }

    public function id(): int|string|null
    {
        $id = $this->session->get(self::SESSION_KEY);

        // The session surface is untyped; only a scalar id is a real login marker.
        return is_int($id) || is_string($id) ? $id : null;
    }

    public function attempt(string $username, string $password): bool
    {
        $user = $this->validate($username, $password);

        if ($user === null) {
            return false;
        }

        $this->login($user);

        return true;
    }

    /**
     * The user these credentials name, without signing them in: what a second
     * factor needs to know before it asks for a code. Announces Attempting and
     * LoginFailed exactly as attempt() does, and costs the same either way.
     */
    public function validate(string $username, string $password): ?AuthenticatableInterface
    {
        // Announced before any lookup, so a listener sees every attempt.
        $this->events?->dispatch(new Attempting($username));

        $user = $this->provider->byUsername($username);
        $hash = $user?->getAuthPassword() ?? '';

        // A missing user, or one with no usable password, must cost the same as
        // a genuine verify: otherwise response timing distinguishes "no such
        // account" from a real wrong-password attempt. Burn exactly one hash at
        // the configured cost, which approximates the work verify() spends on a
        // stored hash made at that cost. Fresh on every miss, deliberately: a
        // cached dummy hash made the FIRST miss pay two hash runs where later
        // misses paid one, and precomputing it in the constructor would bill
        // every request that merely constructs the guard a full hash.
        if ($user === null || $hash === '') {
            $this->hasher->hash($password);
            $this->events?->dispatch(new LoginFailed($username));

            return null;
        }

        if (!$this->hasher->verify($password, $hash)) {
            $this->events?->dispatch(new LoginFailed($username));

            return null;
        }

        return $user;
    }

    public function login(AuthenticatableInterface $user): void
    {
        // A session signing in again without signing out first held a sign-in
        // of its own, which this one replaces.
        $this->revokeHeld();

        // Rotate the id first so the authenticated session can never be the one a
        // pre-login token referred to; regenerate() carries the data over.
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->getAuthIdentifier());
        $this->stamp($user);
        $this->signIn = $this->record($user);

        // Prime the cache: user()/check() later this request need no provider hit.
        $this->cachedUser = $user;
        $this->resolved = true;

        // Announced after the session and cache are set, so a listener that reads
        // the guard already sees the logged-in state.
        $this->events?->dispatch(new LoggedIn($user));
    }

    public function refresh(AuthenticatableInterface $user): void
    {
        if ($this->id() !== $user->getAuthIdentifier()) {
            throw new LogicException('refresh() re-stamps the signed-in user; sign anyone else in with login().');
        }

        // Asked before the new stamp is written: a password that moved has
        // already ended every other session the account had, and their records
        // go too, so a list of sign-ins shows only the ones that still work. A
        // refresh for anything else, a new avatar, ends nothing.
        $passwordChanged = !$this->stampedFor($user);

        $this->session->regenerate();
        $this->stamp($user);

        if ($passwordChanged && $this->signIns !== null) {
            $held = $this->session->get(self::SIGN_IN_KEY);
            $this->signIns->revokeAll($user, except: is_string($held) && $held !== '' ? $held : null);
        }

        $this->cachedUser = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        // Captured before the marker is cleared, since afterwards the guard can
        // no longer say. Null only when logout() ran with nobody logged in.
        $id = $this->id();

        $this->revokeHeld();
        $this->signIn = null;

        // Flush EVERYTHING, not just the auth marker, as OWASP asks on any
        // privilege drop: anything a controller stashed during the authenticated
        // session (cart, profile fragments, CSRF token) belongs to the user who
        // just left, and on a shared machine the next person at the browser
        // would inherit it. Flush BEFORE regenerating, since regenerate() would
        // otherwise carry that data over to the fresh id.
        $this->session->clear();
        $this->session->regenerate();

        $this->cachedUser = null;
        $this->resolved = true;

        $this->events?->dispatch(new LoggedOut($id));
    }

    /**
     * Whether this session's sign-in is still on record, remembering the
     * record if so. A signed-in session that has no record yet began before
     * the app kept them, and is adopted rather than signed out, so turning
     * the store on signs nobody out. A record that is gone, or names somebody
     * else, is a revoked sign-in. A store that cannot answer throws, and is
     * never read as "still signed in".
     */
    private function recorded(AuthenticatableInterface $user): bool
    {
        if ($this->signIns === null) {
            return true;
        }

        $id = $this->session->get(self::SIGN_IN_KEY);

        if (!is_string($id) || $id === '') {
            $this->signIn = $this->record($user);

            return true;
        }

        $signIn = $this->signIns->find($id);

        if ($signIn === null || (string) $signIn->userId !== (string) $user->getAuthIdentifier()) {
            return false;
        }

        $this->signIn = $signIn;

        return true;
    }

    /** A new record for $user, its id kept in the session. Null when the app keeps none. */
    private function record(AuthenticatableInterface $user): ?SignIn
    {
        if ($this->signIns === null || $this->clock === null) {
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $this->session->set(self::SIGN_IN_KEY, $id);

        return $this->signIns->create($id, $user, $this->clock->now());
    }

    /** Deletes the record of the sign-in this session holds, if it holds one. */
    private function revokeHeld(): void
    {
        $id = $this->session->get(self::SIGN_IN_KEY);

        if ($this->signIns !== null && is_string($id) && $id !== '') {
            $this->signIns->revoke($id);
        }
    }

    private function stamp(AuthenticatableInterface $user): void
    {
        $this->session->set(self::DIGEST_KEY, SignedToken::digest($user->getAuthPassword()));
    }

    private function stampedFor(AuthenticatableInterface $user): bool
    {
        $stamp = $this->session->get(self::DIGEST_KEY);

        return is_string($stamp) && hash_equals(SignedToken::digest($user->getAuthPassword()), $stamp);
    }
}
