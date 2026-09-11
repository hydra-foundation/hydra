<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Session\Contracts\SessionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Session guard
 *
 * The session-backed {@see GuardInterface}: authentication state lives in the
 * session as a single stored identifier
 */
final class SessionGuard implements GuardInterface
{
    /** Where the authenticated user's id lives in the session (framework-reserved). */
    private const SESSION_KEY = '_auth_id';

    /** Per-request cache of the resolved user; $resolved distinguishes "null" from "not looked up yet". */
    private ?AuthenticatableInterface $cachedUser = null;
    private bool $resolved = false;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly UserProviderInterface $provider,
        private readonly HasherInterface $hasher,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

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
            // The marker points at a user the provider no longer knows — a
            // deleted account. Left in place it would sit in the session
            // forever: every request would repeat the futile lookup, and the
            // session would keep asserting a login that can never resolve.
            // Remove the marker so the session honestly says "guest" from here
            // on. The session id is deliberately NOT regenerated: this is a
            // read path (user()/check() run on ordinary page views), nothing
            // is being granted — the session only DROPS its claim — and the
            // next real login() rotates the id as it always does.
            $this->session->remove(self::SESSION_KEY);
        }

        return $this->cachedUser = $user;
    }

    public function id(): int|string|null
    {
        $id = $this->session->get(self::SESSION_KEY);

        // The session surface is untyped; only a scalar id is a real login marker.
        return is_int($id) || is_string($id) ? $id : null;
    }

    public function attempt(string $username, string $password): bool
    {
        // Announced before any lookup, so a listener sees every attempt.
        $this->events?->dispatch(new Attempting($username));

        $user = $this->provider->byUsername($username);
        $hash = $user?->getAuthPassword() ?? '';

        // A missing user OR a user with no usable password must cost the same as
        // a genuine verify: otherwise response timing distinguishes "no such
        // account" and "account exists but is passwordless/disabled" from a real
        // wrong-password attempt. Burn exactly one hash at the configured cost —
        // the same work verify() spends on a stored hash made at that cost, so
        // the equalisation is close but not exact (a stored hash carries its own
        // embedded cost): the standard, accepted approximation. Done fresh on
        // every miss, deliberately: a lazily cached dummy hash made the FIRST
        // miss pay hash-then-verify (two hash runs) where later misses paid one
        // — itself a measurable skew — and precomputing it in the constructor
        // would bill every request that merely constructs the guard (any
        // check() on any page) a full hash.
        if ($hash === '') {
            $this->hasher->hash($password);
            $this->events?->dispatch(new LoginFailed($username));

            return false;
        }

        if (!$this->hasher->verify($password, $hash)) {
            $this->events?->dispatch(new LoginFailed($username));

            return false;
        }

        $this->login($user);

        return true;
    }

    public function login(AuthenticatableInterface $user): void
    {
        // Rotate the id first so the authenticated session can never be the one a
        // pre-login token referred to; regenerate() carries the data over.
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->getAuthIdentifier());

        // Prime the cache: user()/check() later this request need no provider hit.
        $this->cachedUser = $user;
        $this->resolved = true;

        // Announced after the session and cache are set, so a listener that reads
        // the guard already sees the logged-in state.
        $this->events?->dispatch(new LoggedIn($user));
    }

    public function logout(): void
    {
        // Capture who it was before the marker is cleared — afterwards the guard
        // can no longer say. Null only when logout() ran with nobody logged in.
        $id = $this->id();

        // Flush EVERYTHING, not just the auth marker: anything a controller
        // stashed during the authenticated session (cart, profile fragments,
        // CSRF token) belongs to the user who just left, and on a shared
        // machine the next person at the browser would inherit it. OWASP says
        // to invalidate the whole session on logout — session hygiene on a
        // privilege drop. Flush BEFORE regenerating: regenerate() carries the
        // current data over to the fresh id, so clearing first guarantees the
        // post-logout session id is never associated with the old data, even
        // transiently.
        $this->session->clear();
        $this->session->regenerate();

        $this->cachedUser = null;
        $this->resolved = true;

        $this->events?->dispatch(new LoggedOut($id));
    }
}
