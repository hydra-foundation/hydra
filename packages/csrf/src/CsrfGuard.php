<?php

declare(strict_types=1);

namespace Hydra\Csrf;

use Hydra\Core\Security\Signer;
use Hydra\Session\Contracts\SessionInterface;

/**
 * The synchronizer-token guard: one secret token per session, compared in
 * constant time against whatever an unsafe request submits
 */
final class CsrfGuard
{
    /** The form field a plain (non-htmx) POST carries the token in. */
    public const FIELD = '_token';

    /** The header an htmx/AJAX request carries the token in. */
    public const HEADER = 'X-CSRF-Token';

    /** Where the token lives in the session (leading-underscore: framework-reserved). */
    private const SESSION_KEY = '_csrf_token';

    /** Token entropy in bytes; 32 bytes → a 64-char hex string. */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Signer $signer,
    ) {}

    /**
     * The session's CSRF token, signed for emission: minted on first read and
     * stable thereafter. Safe to call on every render — repeated calls within a
     * session return an equivalent value (the same underlying token, re-signed).
     * The result is "<hmac>.<hex-token>", all URL/HTML/header-safe characters, so
     * it embeds in HTML attributes, JSON (hx-headers) and headers without further
     * encoding.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $this->signer->sign($token);
    }

    /**
     * Discard the current token and mint, store and return a fresh one.
     *
     * OWASP recommends rotating the CSRF token on authentication (login,
     * privilege escalation) so a token a pre-auth attacker may have captured
     * cannot forge post-auth requests. Note that regenerating the session id
     * ({@see SessionInterface::regenerate()}) does NOT rotate this token — the
     * session data survives the id change — which is exactly why this method
     * exists: the session key is private, so without it an app would have to
     * hardcode '_csrf_token' to force a fresh one.
     *
     * Any token embedded in already-rendered pages stops validating the moment
     * this runs, so call it at the point you are navigating anyway (the login
     * POST handler, before rendering the next page).
     */
    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }

    /**
     * Whether this session has a token minted at all. Read-only — it never
     * mints (unlike {@see token()}).
     *
     * This is the seam an app's error policy needs to tell the two faces of a
     * token mismatch apart: a session WITHOUT a token cannot possibly validate
     * anything — the classic symptom of an expired session behind a stale form
     * (redirect the user to log in again) — while a mismatch against a token
     * that IS issued is a genuine CSRF failure (keep the 403). See the README's
     * "Expired sessions" recipe.
     */
    public function issued(): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '';
    }

    /**
     * Whether $submitted is a validly-signed token matching the session's stored
     * token. The signature is verified first (a forged or tampered value fails
     * here, cheaply), then the recovered token is compared to the stored one in
     * constant time with hash_equals so a mismatch leaks no timing signal.
     *
     * Returns false when nothing was submitted, when the signature does not
     * verify, or when no token has been minted yet — a request can never validate
     * against an absent token. Note this does NOT mint a token (unlike
     * {@see token()}): validation is read-only.
     */
    public function validate(?string $submitted): bool
    {
        if ($submitted === null || $submitted === '') {
            return false;
        }

        $verified = $this->signer->verify($submitted);

        if ($verified === null) {
            return false;
        }

        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($token, $verified);
    }
}
