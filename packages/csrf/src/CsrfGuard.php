<?php

declare(strict_types=1);

namespace Hydra\Csrf;

use Hydra\Core\Security\Signer;
use Hydra\Session\Contracts\SessionInterface;

/**
 * The synchronizer-token guard: one secret token per session, compared in
 * constant time against whatever an unsafe request submits.
 */
final class CsrfGuard
{
    /** The form field a plain (non-htmx) POST carries the token in. */
    public const FIELD = '_token';

    /** The header an htmx/AJAX request carries the token in. */
    public const HEADER = 'X-CSRF-Token';

    /** Where the token lives in the session (leading-underscore: framework-reserved). */
    private const SESSION_KEY = '_csrf_token';

    /** Token entropy in bytes. 32 gives a 64-char hex string. */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Signer $signer,
    ) {}

    /**
     * Returns "<hmac>.<hex-token>", all URL/HTML/header-safe characters, so it
     * embeds in an attribute, in hx-headers JSON and in a header unencoded.
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
     * Call on any privilege change, per OWASP: a token captured pre-auth must
     * not forge post-auth requests. {@see SessionInterface::regenerate()} will
     * not do it, since the session data survives the id change, and the session
     * key here is private. Invalidates every already-rendered page, so rotate
     * where you are navigating anyway.
     */
    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }

    /**
     * Lets an error policy tell the two faces of a mismatch apart. No token at
     * all is an expired session behind a stale form, so send the user back to
     * log in; a mismatch against a token that was issued is a real forgery
     * attempt, so keep the 403. Read-only, unlike {@see token()}.
     */
    public function issued(): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '';
    }

    /**
     * The signature is checked before the token so a forgery fails on the cheap
     * comparison, and the token itself with hash_equals so a near miss leaks no
     * timing signal. Read-only: an absent token validates nothing rather than
     * minting one.
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
