<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\EmailVerificationTokens;
use Hydra\Auth\PasswordResetTokens;
use Hydra\Auth\SignedToken;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordResetTokens::class)]
final class PasswordResetTokensTest extends TestCase
{
    private ArrayUserProvider $users;
    private SignedToken $signed;

    protected function setUp(): void
    {
        $this->users = (new ArrayUserProvider)->add('ada', new FakeUser(1, 'hash-one'));
        $this->signed = new SignedToken(Signer::fromHex(FixedSignerServiceProvider::KEY_HEX), new FrozenClock);
    }

    private function tokens(): PasswordResetTokens
    {
        return new PasswordResetTokens($this->signed, $this->users, 3600);
    }

    public function test_a_fresh_token_resolves_to_its_user(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash-one'));

        $this->assertSame(1, $this->tokens()->resolve($token)?->getAuthIdentifier());
    }

    public function test_resolving_does_not_spend_it(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash-one'));

        $this->tokens()->resolve($token);

        $this->assertNotNull($this->tokens()->resolve($token));
    }

    public function test_a_password_change_spends_it(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash-one'));

        $this->users->add('ada', new FakeUser(1, 'hash-two'));

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_deleted_user_resolves_to_nothing(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash-one'));

        $this->users = new ArrayUserProvider;

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_verification_token_does_not_reset_a_password(): void
    {
        // Both are signed under the app key over a single user. A verification
        // link sits in the same inbox and must not double as a reset.
        $verification = (new EmailVerificationTokens($this->signed, $this->users, 3600))
            ->create(new FakeUser(1, 'hash-one'));

        $this->assertNull($this->tokens()->resolve($verification));
    }
}
