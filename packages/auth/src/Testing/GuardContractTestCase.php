<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\GuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every guard owes its callers, published so a guard the
 * framework does not ship, and the fake that stands in for the one it does,
 * are held to the same thing.
 *
 * Middleware, the gate and every controller that reads the current user ask a
 * guard one of three questions and trust the answers to agree: whether anyone
 * is signed in, who, and by what id. A guard whose check() says yes while
 * user() says nobody lets a request through that the next line cannot serve.
 */
abstract class GuardContractTestCase extends TestCase
{
    /**
     * A guard with nobody signed in, which accepts {@see username()} with
     * {@see password()} and no other credentials.
     */
    abstract protected function guard(): GuardInterface;

    protected function username(): string
    {
        return 'ada';
    }

    protected function password(): string
    {
        return 'correct horse';
    }

    private function signedIn(): GuardInterface
    {
        $guard = $this->guard();

        $this->assertTrue(
            $guard->attempt($this->username(), $this->password()),
            'The guard does not accept its own known credentials.',
        );

        return $guard;
    }

    private function assertGuest(GuardInterface $guard): void
    {
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function test_it_starts_with_nobody_signed_in(): void
    {
        $this->assertGuest($this->guard());
    }

    public function test_the_right_credentials_sign_the_user_in(): void
    {
        $guard = $this->signedIn();

        $this->assertTrue($guard->check());
        $this->assertNotNull($guard->user());
    }

    public function test_id_is_the_signed_in_users_identifier(): void
    {
        // id() exists to skip the provider lookup, so it is the one answer
        // nothing else cross-checks.
        $guard = $this->signedIn();

        $this->assertSame($guard->user()?->getAuthIdentifier(), $guard->id());
    }

    public function test_a_wrong_password_signs_nobody_in(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->attempt($this->username(), $this->password() . 'x'));
        $this->assertGuest($guard);
    }

    public function test_an_unknown_username_signs_nobody_in(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->attempt('nobody-by-that-name', $this->password()));
        $this->assertGuest($guard);
    }

    public function test_an_empty_password_signs_nobody_in(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->attempt($this->username(), ''));
        $this->assertGuest($guard);
    }

    public function test_login_signs_in_the_user_it_was_given(): void
    {
        $user = $this->signedIn()->user();
        $this->assertNotNull($user);
        $guard = $this->guard();

        $guard->login($user);

        $this->assertTrue($guard->check());
        $this->assertSame($user->getAuthIdentifier(), $guard->id());
        $this->assertSame($user->getAuthIdentifier(), $guard->user()?->getAuthIdentifier());
    }

    public function test_refresh_keeps_the_signed_in_user_signed_in(): void
    {
        $guard = $this->signedIn();
        $user = $guard->user();
        $this->assertNotNull($user);

        $guard->refresh($user);

        $this->assertSame($user->getAuthIdentifier(), $guard->id());
        $this->assertSame($user->getAuthIdentifier(), $guard->user()?->getAuthIdentifier());
    }

    public function test_logout_leaves_nobody_signed_in(): void
    {
        $guard = $this->signedIn();

        $guard->logout();

        $this->assertGuest($guard);
    }

    public function test_logout_with_nobody_signed_in_is_harmless(): void
    {
        $guard = $this->guard();

        $guard->logout();

        $this->assertGuest($guard);
    }
}
