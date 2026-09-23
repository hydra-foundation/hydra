<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\EmailVerificationTokens;
use Hydra\Auth\SignedToken;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailVerificationTokens::class)]
final class EmailVerificationTokensTest extends TestCase
{
    private ArrayUserProvider $users;
    private SignedToken $signed;

    protected function setUp(): void
    {
        $this->users = (new ArrayUserProvider)->add('ada', new FakeUser(1, 'hash', 'ada@example.com'));
        $this->signed = new SignedToken(Signer::fromHex(FixedSignerServiceProvider::KEY_HEX), new FrozenClock);
    }

    private function tokens(): EmailVerificationTokens
    {
        return new EmailVerificationTokens($this->signed, $this->users, 86400);
    }

    public function test_a_fresh_token_resolves_to_its_user(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash', 'ada@example.com'));

        $this->assertSame('ada@example.com', $this->tokens()->resolve($token)?->getAuthEmail());
    }

    public function test_changing_the_address_spends_it(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash', 'ada@example.com'));

        $this->users->add('ada', new FakeUser(1, 'hash', 'ada@elsewhere.test'));

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_password_change_does_not_spend_it(): void
    {
        $token = $this->tokens()->create(new FakeUser(1, 'hash', 'ada@example.com'));

        $this->users->add('ada', new FakeUser(1, 'new-hash', 'ada@example.com'));

        $this->assertNotNull($this->tokens()->resolve($token));
    }

    public function test_a_user_without_an_address_resolves_to_nothing(): void
    {
        // The provider hands back whatever the app's model is. One that never
        // opted into HasEmailInterface has no mailbox to have verified.
        $token = $this->tokens()->create(new FakeUser(1, 'hash', 'ada@example.com'));

        $this->users->add('ada', new class implements AuthenticatableInterface {
            public function getAuthIdentifier(): int
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return 'hash';
            }
        });

        $this->assertNull($this->tokens()->resolve($token));
    }
}
