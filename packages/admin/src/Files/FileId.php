<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Hydra\Filesystem\Disks;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;

/**
 * A stored file as a row id: the qualified key in base64url, unpadded.
 *
 * The admin puts a row's id in one path segment, and the id has to survive
 * two things on the way. A qualified key has slashes in it, and an encoded
 * slash is refused by plenty of servers. It also ends in an extension, and a
 * web server's static-file rules (nginx's "location ~* \.(?:jpg|png)$", say)
 * answer a path ending in one without ever asking PHP, which makes a show
 * screen at /admin/files/<id>.jpg a 404. Base64url is letters, digits, "-" and
 * "_", so it has neither problem. It is opaque, and a row is clicked, not
 * typed.
 */
final class FileId
{
    private function __construct() {}

    /** @throws InvalidKey when $qualified is not a key on one of the disks */
    public static function of(string $qualified): string
    {
        self::split($qualified) ?? throw InvalidKey::of($qualified);

        return rtrim(strtr(base64_encode($qualified), '+/', '-_'), '=');
    }

    /** The qualified key an id names, or null when it names none. */
    public static function qualified(string $id): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) {
            return null;
        }

        $qualified = base64_decode(strtr($id, '-_', '+/'), true);

        // One spelling per file: an id that decodes but is not what of() would
        // write (trailing bits set, say) is not that file's id.
        if ($qualified === false || self::split($qualified) === null || self::of($qualified) !== $id) {
            return null;
        }

        return $qualified;
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
