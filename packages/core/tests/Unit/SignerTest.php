<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Security\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    /** A 64-hex (32-byte) key, the shape `key:generate` emits. */
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    private const OTHER_HEX = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    public function test_sign_then_verify_round_trips_the_message(): void
    {
        $signer = Signer::fromHex(self::KEY_HEX);

        $signed = $signer->sign('hello world');

        $this->assertSame('hello world', $signer->verify($signed));
    }

    public function test_signed_value_is_the_hmac_then_a_dot_then_the_message(): void
    {
        $signer = Signer::fromHex(self::KEY_HEX);

        $signed = $signer->sign('payload');

        // 64 hex chars, a dot, then the verbatim message.
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}\.payload$/', $signed));
    }

    public function test_a_message_containing_dots_still_round_trips(): void
    {
        // Signature-first framing means the message may contain any bytes.
        $signer = Signer::fromHex(self::KEY_HEX);
        $message = 'a.b.c.d.e';

        $this->assertSame($message, $signer->verify($signer->sign($message)));
    }

    public function test_verify_rejects_a_tampered_message(): void
    {
        $signer = Signer::fromHex(self::KEY_HEX);
        $signed = $signer->sign('amount=10');

        // Same signature, different message.
        $forged = substr($signed, 0, 65) . 'amount=99999';

        $this->assertNull($signer->verify($forged));
    }

    public function test_verify_rejects_a_message_signed_under_a_different_key(): void
    {
        $signed = Signer::fromHex(self::OTHER_HEX)->sign('hello');

        $this->assertNull(Signer::fromHex(self::KEY_HEX)->verify($signed));
    }

    public function test_verify_accepts_a_message_signed_under_a_previous_key(): void
    {
        $oldSigned = Signer::fromHex(self::OTHER_HEX)->sign('still valid');

        // Current key is KEY_HEX; OTHER_HEX is a rotated-out previous key.
        $rotated = Signer::fromHex(self::KEY_HEX, [self::OTHER_HEX]);

        $this->assertSame('still valid', $rotated->verify($oldSigned));
    }

    public function test_verify_returns_null_for_malformed_input_without_throwing(): void
    {
        $signer = Signer::fromHex(self::KEY_HEX);

        $this->assertNull($signer->verify(''));
        $this->assertNull($signer->verify('no-dot-at-all'));
        $this->assertNull($signer->verify('short.'));
        // A dot in the wrong place (not at offset 64).
        $this->assertNull($signer->verify('abc.def'));
    }

    public function test_from_hex_rejects_a_non_hex_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        Signer::fromHex('nothex!!' . str_repeat('0', 56));
    }

    public function test_from_hex_rejects_a_key_that_is_too_short(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Valid hex but only 16 bytes decoded.
        Signer::fromHex(str_repeat('ab', 16));
    }

    public function test_constructor_rejects_raw_key_below_the_minimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Signer('too-short');
    }

    public function test_constructor_accepts_a_thirty_two_byte_raw_key(): void
    {
        $signer = new Signer(str_repeat('k', 32));

        $this->assertSame('x', $signer->verify($signer->sign('x')));
    }
}
