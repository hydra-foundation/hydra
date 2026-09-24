<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

use InvalidArgumentException;
use SodiumException;

/**
 * XChaCha20-Poly1305 under a key derived from the application key, so what it
 * seals can be neither read nor altered without that key.
 *
 * Derived rather than used as is: {@see Signer} holds the same key for HMAC,
 * and one key serving two primitives is how a weakness in one reaches the
 * other.
 *
 * A context is bound in as associated data. Sealed with one, a value opens
 * only with the same one, so a ciphertext lifted from one column or cookie is
 * refused in another.
 */
final class Encrypter
{
    /** Also bound into the associated data, so a later format cannot be read as this one. */
    private const VERSION = 'v1.';

    private const INFO = 'hydra.encrypter.v1';

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /** @var non-empty-list<string> derived keys, current first */
    private readonly array $keys;

    /**
     * @param non-empty-string $key raw key bytes (>= 32)
     * @param list<non-empty-string> $previousKeys raw key bytes tried on decrypt() only, for rotation
     */
    public function __construct(string $key, array $previousKeys = [])
    {
        $keys = [];

        foreach ([$key, ...$previousKeys] as $raw) {
            if (strlen($raw) < AppKey::MIN_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'Encryption key must be at least %d bytes (256-bit); got %d.',
                    AppKey::MIN_BYTES,
                    strlen($raw),
                ));
            }

            $keys[] = hash_hkdf('sha256', $raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO);
        }

        $this->keys = $keys;
    }

    /**
     * @param list<string> $previousHex keys still accepted on decrypt, newest first
     */
    public static function fromHex(string $hex, array $previousHex = []): self
    {
        return new self(AppKey::decode($hex), array_map(AppKey::decode(...), $previousHex));
    }

    /** "v1.<base64url of nonce, ciphertext and tag>", safe in a cookie, a URL or a column. */
    public function encrypt(string $plaintext, string $context = ''): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::VERSION . $context,
            $nonce,
            $this->keys[0],
        );

        return self::VERSION . sodium_bin2base64($nonce . $sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * The plaintext if $encrypted opens under the current key or any previous
     * one with the same context; otherwise null. A forged, truncated or
     * rotated-out value is ordinary input, not a fault.
     */
    public function decrypt(string $encrypted, string $context = ''): ?string
    {
        if (!str_starts_with($encrypted, self::VERSION)) {
            return null;
        }

        try {
            $raw = sodium_base642bin(substr($encrypted, strlen(self::VERSION)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }

        if (strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            return null;
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $sealed = substr($raw, self::NONCE_BYTES);

        foreach ($this->keys as $key) {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, self::VERSION . $context, $nonce, $key);

            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        return null;
    }
}
