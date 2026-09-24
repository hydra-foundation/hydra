<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

/**
 * HMAC-SHA256 message signing under an explicitly-injected key.
 */
final class Signer
{
    /** SHA-256 HMAC rendered as hex: always 64 chars, so the framing below is fixed-width. */
    private const HMAC_HEX_LEN = 64;

    /**
     * @param non-empty-string $key raw key bytes (>= 32)
     * @param list<non-empty-string> $previousKeys raw key bytes tried on verify() only, for rotation
     */
    public function __construct(
        private readonly string $key,
        private readonly array $previousKeys = [],
    ) {
        if (strlen($key) < AppKey::MIN_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Signing key must be at least %d bytes (256-bit); got %d.',
                AppKey::MIN_BYTES,
                strlen($key),
            ));
        }
    }

    /**
     * Seal a message: "<64-hex-hmac>.<message>".
     */
    public function sign(string $message): string
    {
        return $this->hmac($message, $this->key) . '.' . $message;
    }

    /**
     * The original message if $signed verifies (constant-time) under the current
     * key or any previous key; otherwise null.
     */
    public function verify(string $signed): ?string
    {
        // "<64 hex>.<message>": at least the signature, the dot, and one byte,
        // with the dot exactly where the fixed-width signature ends.
        if (strlen($signed) < self::HMAC_HEX_LEN + 1 || $signed[self::HMAC_HEX_LEN] !== '.') {
            return null;
        }

        $signature = substr($signed, 0, self::HMAC_HEX_LEN);
        $message = substr($signed, self::HMAC_HEX_LEN + 1);

        foreach ([$this->key, ...$this->previousKeys] as $key) {
            if (hash_equals($this->hmac($message, $key), $signature)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Build a Signer from Hydra's canonical key format: a hex string (64+ chars)
     * decoded to raw bytes.
     *
     * @param list<string> $previousHex keys still accepted on verify, newest first
     */
    public static function fromHex(string $hex, array $previousHex = []): self
    {
        return new self(AppKey::decode($hex), array_map(AppKey::decode(...), $previousHex));
    }

    private function hmac(string $message, string $key): string
    {
        return hash_hmac('sha256', $message, $key);
    }
}
