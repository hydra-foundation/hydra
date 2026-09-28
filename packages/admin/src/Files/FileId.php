<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Hydra\Filesystem\Disks;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;

/**
 * A stored file as a row id. The admin puts a row's id in one path segment,
 * and a qualified key has a colon and slashes in it, which an encoded slash
 * would not survive (plenty of servers refuse %2F). Both become "~", which no
 * key can contain, so the id reads back unambiguously:
 * "private:avatars/ab12.png" is "private~avatars~ab12.png".
 */
final class FileId
{
    private const SEPARATOR = '~';

    private function __construct() {}

    /** @throws InvalidKey when $qualified is not a key on one of the disks */
    public static function of(string $qualified): string
    {
        [$disk, $key] = self::split($qualified) ?? throw InvalidKey::of($qualified);

        return $disk . self::SEPARATOR . str_replace('/', self::SEPARATOR, $key);
    }

    /** The qualified key an id names, or null when it names none. */
    public static function qualified(string $id): ?string
    {
        if (str_contains($id, ':') || str_contains($id, '/')) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $id, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $qualified = $parts[0] . ':' . str_replace(self::SEPARATOR, '/', $parts[1]);

        return self::split($qualified) === null ? null : $qualified;
    }

    /** @return array{string, string}|null */
    private static function split(string $qualified): ?array
    {
        $parts = explode(':', $qualified);

        if (count($parts) !== 2 || !in_array($parts[0], [Disks::PRIVATE, Disks::PUBLIC], true)) {
            return null;
        }

        try {
            return [$parts[0], Key::valid($parts[1])];
        } catch (InvalidKey) {
            return null;
        }
    }
}
