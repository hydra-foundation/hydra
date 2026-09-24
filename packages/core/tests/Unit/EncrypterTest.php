<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Security\AppKey;
use Hydra\Core\Security\Encrypter;
use Hydra\Core\Security\Signer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encrypter::class)]
#[CoversClass(AppKey::class)]
final class EncrypterTest extends TestCase
{
    private const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private const OTHER = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    public function test_what_it_seals_it_opens(): void
    {
        $encrypter = Encrypter::fromHex(self::KEY);

        $this->assertSame('a secret', $encrypter->decrypt($encrypter->encrypt('a secret')));
        $this->assertSame('', $encrypter->decrypt($encrypter->encrypt('')));
        $this->assertSame("\0\xff binary", $encrypter->decrypt($encrypter->encrypt("\0\xff binary")));
    }

    public function test_the_sealed_form_is_versioned_urlsafe_and_hides_the_plaintext(): void
    {
        $sealed = Encrypter::fromHex(self::KEY)->encrypt('a secret');

        $this->assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+$/', $sealed);
        $this->assertStringNotContainsString('secret', $sealed);
        // 24-byte nonce, 8 bytes of plaintext and a 16-byte tag, base64url without padding.
        $this->assertSame(3 + 64, strlen($sealed));
    }

    public function test_sealing_twice_gives_two_ciphertexts(): void
    {
        $encrypter = Encrypter::fromHex(self::KEY);

        $this->assertNotSame($encrypter->encrypt('same'), $encrypter->encrypt('same'));
    }

    public function test_a_context_must_match_to_open(): void
    {
        $encrypter = Encrypter::fromHex(self::KEY);
        $sealed = $encrypter->encrypt('123-45-678', 'users.tax_id');

        $this->assertSame('123-45-678', $encrypter->decrypt($sealed, 'users.tax_id'));
        $this->assertNull($encrypter->decrypt($sealed, 'users.phone'));
        $this->assertNull($encrypter->decrypt($sealed));
        $this->assertNull($encrypter->decrypt($encrypter->encrypt('123-45-678'), 'users.tax_id'));
    }

    public function test_another_key_cannot_open_it(): void
    {
        $sealed = Encrypter::fromHex(self::KEY)->encrypt('a secret');

        $this->assertNull(Encrypter::fromHex(self::OTHER)->decrypt($sealed));
    }

    public function test_a_previous_key_still_opens_and_the_current_one_seals(): void
    {
        $old = Encrypter::fromHex(self::OTHER);
        $rotated = Encrypter::fromHex(self::KEY, [self::OTHER]);

        $this->assertSame('before', $rotated->decrypt($old->encrypt('before')));
        $this->assertSame('after', Encrypter::fromHex(self::KEY)->decrypt($rotated->encrypt('after')));
        $this->assertNull($old->decrypt($rotated->encrypt('after')));
    }

    public function test_it_does_not_seal_under_the_raw_application_key(): void
    {
        $sealed = Encrypter::fromHex(self::KEY)->encrypt('a secret');
        $raw = sodium_base642bin(substr($sealed, 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $opened = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, 24),
            'v1.',
            substr($raw, 0, 24),
            AppKey::decode(self::KEY),
        );

        $this->assertFalse($opened, 'the key Signer holds must not also be the cipher key');
    }

    public function test_every_altered_byte_is_refused(): void
    {
        $encrypter = Encrypter::fromHex(self::KEY);
        $raw = sodium_base642bin(substr($encrypter->encrypt('a secret'), 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        for ($i = 0, $len = strlen($raw); $i < $len; $i++) {
            $altered = $raw;
            $altered[$i] = chr(ord($altered[$i]) ^ 1);

            $this->assertNull(
                $encrypter->decrypt('v1.' . sodium_bin2base64($altered, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)),
                "byte {$i} was altered and still opened",
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'no version' => ['AAAA'];
        yield 'another version' => ['v2.' . str_repeat('A', 64)];
        yield 'version alone' => ['v1.'];
        yield 'not base64url' => ['v1.***'];
        yield 'padded base64' => ['v1.' . str_repeat('A', 62) . '=='];
        yield 'shorter than a nonce and a tag' => ['v1.' . sodium_bin2base64(str_repeat("\0", 39), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
        yield 'a nonce and a tag of zeroes' => ['v1.' . sodium_bin2base64(str_repeat("\0", 40), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
        yield 'a signed value' => [Signer::fromHex(self::KEY)->sign('a secret')];
    }

    #[DataProvider('malformed')]
    public function test_what_it_did_not_seal_is_null_and_never_a_throw(string $input): void
    {
        $this->assertNull(Encrypter::fromHex(self::KEY)->decrypt($input));
    }

    public function test_a_short_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must be at least 32 bytes (256-bit); got 31.');

        new Encrypter(str_repeat('k', 31));
    }

    public function test_a_short_previous_key_is_refused_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got 16.');

        new Encrypter(str_repeat('k', 32), [str_repeat('p', 16)]);
    }

    public function test_a_thirty_two_byte_key_is_enough(): void
    {
        $encrypter = new Encrypter(str_repeat('k', 32));

        $this->assertSame('ok', $encrypter->decrypt($encrypter->encrypt('ok')));
    }

    /** @return iterable<string, array{string}> */
    public static function badHex(): iterable
    {
        yield 'not hex' => [str_repeat('zz', 32)];
        yield 'odd length' => [self::KEY . 'a'];
        yield 'too short' => [str_repeat('ab', 31)];
    }

    #[DataProvider('badHex')]
    public function test_a_malformed_hex_key_names_key_generate(string $hex): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('run `php bin/console key:generate` to create one.');

        Encrypter::fromHex($hex);
    }

    public function test_a_malformed_previous_hex_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Encrypter::fromHex(self::KEY, ['nope']);
    }
}
