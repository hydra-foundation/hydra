<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\EmailChange;
use Hydra\Auth\EmailChangeTokens;
use Hydra\Auth\SignedToken;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailChangeTokens::class)]
#[CoversClass(EmailChange::class)]
final class EmailChangeTokensTest extends TestCase
{
    private ArrayUserProvider $users;
    private SignedToken $signed;

    protected function setUp(): void
    {
        $this->users = (new ArrayUserProvider)->add('ada', new FakeUser(1, 'hash', 'ada@example.com'));
        $this->signed = new SignedToken(Signer::fromHex(FixedSignerServiceProvider::KEY_HEX), new FrozenClock);
    }

    private function tokens(): EmailChangeTokens
    {
        return new EmailChangeTokens($this->signed, $this->users, 86400);
    }

    private function mint(): string
    {
        return $this->tokens()->create(new FakeUser(1, 'hash', 'ada@example.com'), 'ada@elsewhere.test');
    }

    public function test_a_fresh_token_resolves_to_the_user_and_the_new_address(): void
    {
        $change = $this->tokens()->resolve($this->mint());

        $this->assertSame(1, $change?->user->getAuthIdentifier());
        $this->assertSame('ada@elsewhere.test', $change->email);
    }

    public function test_the_move_itself_spends_it(): void
    {
        $token = $this->mint();

        $this->users->add('ada', new FakeUser(1, 'hash', 'ada@elsewhere.test'));

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_password_change_cancels_it(): void
    {
        $token = $this->mint();

        $this->users->add('ada', new FakeUser(1, 'new-hash', 'ada@example.com'));

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_verification_token_does_not_open_it(): void
    {
        $token = $this->signed->mint('email-verify', 86400, 1, "ada@example.com\0hash", 'ada@elsewhere.test');

        $this->assertNull($this->tokens()->resolve($token));
    }

    public function test_a_token_carrying_no_address_resolves_to_nothing(): void
    {
        $token = $this->signed->mint('email-change', 86400, 1, "ada@example.com\0hash");

        $this->assertNull($this->tokens()->resolve($token));
    }
}
