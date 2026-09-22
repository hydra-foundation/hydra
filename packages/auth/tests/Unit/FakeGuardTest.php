<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\GuardContractTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The fake against the contract the session guard answers, plus the recording
 * it exists for.
 */
#[CoversClass(FakeGuard::class)]
#[CoversClass(FakeUser::class)]
final class FakeGuardTest extends GuardContractTestCase
{
    protected function guard(): GuardInterface
    {
        return FakeGuard::guest()->accepting($this->username(), $this->password(), new FakeUser(1));
    }

    public function test_signed_in_as_starts_with_that_user(): void
    {
        $user = new FakeUser(7);
        $guard = FakeGuard::signedInAs($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame(7, $guard->id());
        $guard->assertSignedIn($user);
    }

    public function test_it_records_attempts_and_their_outcomes_in_order(): void
    {
        $guard = FakeGuard::guest()->accepting('ada', 'pw', new FakeUser(1));
        $guard->attempt('ada', 'wrong');
        $guard->attempt('ada', 'pw');

        $this->assertSame([
            ['username' => 'ada', 'succeeded' => false],
            ['username' => 'ada', 'succeeded' => true],
        ], $guard->attempts());
        $guard->assertAttempted('ada');
        $guard->assertAttempted('ada', succeeded: false);
        $guard->assertAttempted('ada', succeeded: true);
    }

    public function test_assert_attempted_fails_on_the_wrong_outcome(): void
    {
        $guard = FakeGuard::guest();
        $guard->attempt('ada', 'pw');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No successful attempt was made for ada.');
        $guard->assertAttempted('ada', succeeded: true);
    }

    public function test_assert_attempted_fails_when_the_username_was_never_tried(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No attempt was made for ada.');
        FakeGuard::guest()->assertAttempted('ada');
    }

    public function test_assert_not_attempted(): void
    {
        $guard = FakeGuard::guest();
        $guard->assertNotAttempted();
        $guard->attempt('ada', 'pw');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 attempts were made.');
        $guard->assertNotAttempted();
    }

    public function test_assert_signed_in_compares_by_identifier(): void
    {
        // A controller that reloaded the user holds an equal object, not the
        // same one, and that is still the right user.
        FakeGuard::signedInAs(new FakeUser(7))->assertSignedIn(new FakeUser(7));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('A different user is signed in.');
        FakeGuard::signedInAs(new FakeUser(7))->assertSignedIn(new FakeUser(8));
    }

    public function test_assert_signed_in_fails_for_a_guest(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Nobody is signed in.');
        FakeGuard::guest()->assertSignedIn();
    }

    public function test_assert_guest_fails_when_somebody_is_signed_in(): void
    {
        FakeGuard::guest()->assertGuest();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Somebody is signed in.');
        FakeGuard::signedInAs(new FakeUser(1))->assertGuest();
    }

    public function test_assert_logged_out_asks_whether_logout_was_called(): void
    {
        // Not the same as assertGuest(): a guest who was never signed in did
        // not log out, and a controller that forgot to call it still leaves one.
        $guard = FakeGuard::signedInAs(new FakeUser(1));
        $guard->logout();
        $guard->assertLoggedOut();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('logout() was never called.');
        FakeGuard::guest()->assertLoggedOut();
    }
}
