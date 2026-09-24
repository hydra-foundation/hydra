<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\SignedToken;
use Hydra\Auth\TokenClaims;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedToken::class)]
#[CoversClass(TokenClaims::class)]
final class SignedTokenTest extends TestCase
{
    private const TTL = 3600;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
    }

    private function tokens(?Signer $signer = null): SignedToken
    {
        return new SignedToken($signer ?? Signer::fromHex(FixedSignerServiceProvider::KEY_HEX), $this->clock);
    }

    public function test_a_fresh_token_opens_to_its_claims(): void
    {
        $claims = $this->tokens()->open('reset', $this->tokens()->mint('reset', self::TTL, 1, 'hash-one'));

        $this->assertSame(1, $claims?->id);
        $this->assertTrue($claims->bindsTo('hash-one'));
        $this->assertFalse($claims->bindsTo('hash-two'));
    }

    public function test_a_carried_value_comes_back_as_it_went_in(): void
    {
        $token = $this->tokens()->mint('change', self::TTL, 1, 'x', 'a|b@example.com');

        $this->assertSame('a|b@example.com', $this->tokens()->open('change', $token)?->carried);
    }

    public function test_nothing_carried_opens_as_empty(): void
    {
        $this->assertSame('', $this->tokens()->open('reset', $this->tokens()->mint('reset', self::TTL, 1, 'x'))?->carried);
    }

    public function test_a_tampered_carried_value_is_refused(): void
    {
        $token = $this->tokens()->mint('change', self::TTL, 1, 'x', 'mine@example.com');

        $forged = str_replace(base64_encode('mine@example.com'), base64_encode('them@example.com'), $this->decode($token));

        $this->assertNull($this->tokens()->open('change', $this->encode($forged)));
    }

    public function test_the_token_is_url_safe(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $this->tokens()->mint('reset', self::TTL, 1, 'x'));
    }

    public function test_it_holds_until_the_last_second_of_its_lifetime(): void
    {
        $token = $this->tokens()->mint('reset', self::TTL, 1, 'x');

        $this->clock->advance('+' . self::TTL . ' seconds');
        $this->assertNotNull($this->tokens()->open('reset', $token));

        $this->clock->advance('+1 second');
        $this->assertNull($this->tokens()->open('reset', $token));
    }

    public function test_the_bound_value_never_appears_in_the_token(): void
    {
        $hash = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]);

        $token = $this->tokens()->mint('reset', self::TTL, 1, $hash);

        $this->assertStringNotContainsString($hash, $this->decode($token));
    }

    public function test_a_token_for_another_purpose_does_not_open(): void
    {
        $token = $this->tokens()->mint('verify', self::TTL, 1, 'x');

        $this->assertNull($this->tokens()->open('reset', $token));
    }

    public function test_a_token_under_another_key_is_refused(): void
    {
        $token = $this->tokens(Signer::fromHex(str_repeat('ab', 32)))->mint('reset', self::TTL, 1, 'x');

        $this->assertNull($this->tokens()->open('reset', $token));
    }

    public function test_a_token_under_a_rotated_out_key_still_opens(): void
    {
        $old = str_repeat('ab', 32);
        $token = $this->tokens(Signer::fromHex($old))->mint('reset', self::TTL, 1, 'x');

        $rotated = Signer::fromHex(FixedSignerServiceProvider::KEY_HEX, [$old]);

        $this->assertNotNull($this->tokens($rotated)->open('reset', $token));
    }

    public function test_a_tampered_identifier_is_refused(): void
    {
        $token = $this->tokens()->mint('reset', self::TTL, 1, 'x');

        $forged = substr($this->decode($token), 0, -1) . '2';

        $this->assertNull($this->tokens()->open('reset', $this->encode($forged)));
    }

    public function test_a_string_identifier_keeps_its_type(): void
    {
        $token = $this->tokens()->mint('reset', self::TTL, '007|x', 'x');

        $this->assertSame('007|x', $this->tokens()->open('reset', $token)?->id);
    }

    public function test_garbage_does_not_open(): void
    {
        $this->assertNull($this->tokens()->open('reset', ''));
        $this->assertNull($this->tokens()->open('reset', 'not a token'));
        $this->assertNull($this->tokens()->open('reset', $this->encode('short')));
    }

    private function decode(string $token): string
    {
        return (string) base64_decode(strtr($token, '-_', '+/'));
    }

    private function encode(string $signed): string
    {
        return rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');
    }
}
