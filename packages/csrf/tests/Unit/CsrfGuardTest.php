<?php

declare(strict_types=1);

namespace Hydra\Csrf\Tests\Unit;

use Hydra\Core\Security\Signer;
use Hydra\Csrf\CsrfGuard;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\TestCase;

/**
 * The guard is exercised against the real in-memory ArraySessionStore — the same
 * reference backend the session package tests with — and a real {@see Signer}
 * under a fixed test key, so these prove the actual session read/write and
 * sign/verify paths, not mocks of them.
 */
final class CsrfGuardTest extends TestCase
{
    /** A fixed 64-hex (32-byte) key so signatures are reproducible across guards. */
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    public function test_token_is_minted_once_and_stable(): void
    {
        $guard = $this->guard();

        $first = $guard->token();
        $second = $guard->token();

        // Lazily minted, then stable for the life of the session.
        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
        // Emitted as "<64-hex-hmac>.<64-hex-token>" — signed, not the bare token.
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}\.[0-9a-f]{64}$/', $first));
    }

    public function test_emitted_token_is_signed_not_the_stored_value(): void
    {
        $store = $this->startedStore();
        $emitted = $this->guard($store)->token();

        // The session stores the raw random token; the guard emits it signed, so
        // the two differ and the signature prefixes the stored value.
        $stored = $store->get('_csrf_token');
        $this->assertIsString($stored);
        $this->assertNotSame($stored, $emitted);
        $this->assertStringEndsWith('.' . $stored, $emitted);
    }

    public function test_distinct_sessions_get_distinct_tokens(): void
    {
        $a = $this->guard()->token();
        $b = $this->guard()->token();

        $this->assertNotSame($a, $b);
    }

    public function test_validate_accepts_the_minted_token(): void
    {
        $guard = $this->guard();
        $token = $guard->token();

        $this->assertTrue($guard->validate($token));
    }

    public function test_validate_rejects_a_wrong_token(): void
    {
        $guard = $this->guard();
        $guard->token();

        $this->assertFalse($guard->validate('not-the-token'));
    }

    public function test_validate_rejects_a_tampered_token(): void
    {
        // A validly-signed token whose message half is swapped for another
        // session's stored value must fail — the signature no longer matches.
        $guard = $this->guard();
        $signed = $guard->token();

        $forged = substr($signed, 0, 65) . bin2hex(random_bytes(32));

        $this->assertFalse($guard->validate($forged));
    }

    public function test_validate_rejects_a_token_signed_under_a_different_key(): void
    {
        // Same underlying session token, but sealed with a foreign key: the
        // guard's Signer cannot verify it, so it never reaches the store compare.
        $store = $this->startedStore();
        $guard = $this->guard($store);
        $guard->token();
        $stored = $store->get('_csrf_token');

        $foreign = (new Signer(str_repeat('x', 32)))->sign((string) $stored);

        $this->assertFalse($guard->validate($foreign));
    }

    public function test_validate_rejects_null_and_empty(): void
    {
        $guard = $this->guard();
        $guard->token();

        $this->assertFalse($guard->validate(null));
        $this->assertFalse($guard->validate(''));
    }

    public function test_validate_is_false_before_any_token_is_minted(): void
    {
        // No token has been issued for this session, so nothing can validate —
        // not even a value validly signed under this key. validate() must not
        // mint one.
        $guard = $this->guard();

        $this->assertFalse($guard->validate('anything'));
        $this->assertFalse($guard->validate($this->signer()->sign(bin2hex(random_bytes(32)))));
    }

    public function test_rotate_returns_a_fresh_token(): void
    {
        $guard = $this->guard();
        $old = $guard->token();

        $new = $guard->rotate();

        // A genuinely new token, well-formed like any minted one, and now the
        // stable one for the session.
        $this->assertNotSame($old, $new);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}\.[0-9a-f]{64}$/', $new));
        $this->assertSame($new, $guard->token());
    }

    public function test_rotate_invalidates_the_old_token_and_honours_the_new(): void
    {
        // The login-rotation use case: after rotate(), a token captured before
        // authentication must stop validating, while the fresh one works.
        $guard = $this->guard();
        $old = $guard->token();

        $new = $guard->rotate();

        $this->assertFalse($guard->validate($old));
        $this->assertTrue($guard->validate($new));
    }

    public function test_a_second_guard_on_the_same_session_shares_the_token(): void
    {
        // The guard is stateless: all state lives in the session and both guards
        // sign under the same key, so a separate instance (e.g. the one in the
        // view vs the one in the middleware) validates the very same token.
        $store = $this->startedStore();
        $minted = $this->guard($store)->token();

        $this->assertTrue($this->guard($store)->validate($minted));
    }

    public function test_issued_is_false_until_a_token_is_minted(): void
    {
        // A fresh session (the expired-session symptom an app's error policy
        // keys on: no token here can ever validate) reports not-issued...
        $guard = $this->guard();

        $this->assertFalse($guard->issued());

        // ...and minting flips it, for THIS session only.
        $guard->token();
        $this->assertTrue($guard->issued());
    }

    public function test_issued_is_read_only_and_never_mints(): void
    {
        $guard = $this->guard();

        $guard->issued();
        $guard->issued();

        // No token appeared as a side effect: validate still refuses everything.
        $this->assertFalse($guard->issued());
        $this->assertFalse($guard->validate('anything'));
    }

    public function test_issued_survives_rotation(): void
    {
        // rotate() replaces the token, it never leaves the session bare.
        $guard = $this->guard();
        $guard->token();

        $guard->rotate();

        $this->assertTrue($guard->issued());
    }

    private function guard(?ArraySessionStore $store = null): CsrfGuard
    {
        return new CsrfGuard($store ?? $this->startedStore(), $this->signer());
    }

    private function signer(): Signer
    {
        return Signer::fromHex(self::KEY_HEX);
    }

    /** The store as the middleware hands it to request code: already started. */
    private function startedStore(): ArraySessionStore
    {
        $store = new ArraySessionStore;
        $store->start();

        return $store;
    }
}
