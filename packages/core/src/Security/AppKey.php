<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

use InvalidArgumentException;

/**
 * Hydra's key format: a hex string of at least 64 characters, which is what
 * `key:generate` writes to APP_KEY and what APP_PREVIOUS_KEYS lists.
 */
final class AppKey
{
    /** 256-bit, matching `key:generate`'s output. */
    public const MIN_BYTES = 32;

    /** @return non-empty-string raw key bytes */
    public static function decode(string $hex): string
    {
        $raw = @hex2bin($hex); // strict: false on non-hex or odd length

        if ($raw === false || strlen($raw) < self::MIN_BYTES) {
            throw new InvalidArgumentException(
                'APP_KEY must be a hex string of at least 64 characters (256-bit); '
                . 'run `php bin/console key:generate` to create one.',
            );
        }

        return $raw;
    }
}
