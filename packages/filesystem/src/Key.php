<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use Hydra\Filesystem\Exceptions\InvalidKey;

/**
 * The one place that decides what a key may look like, so every driver refuses
 * the same things.
 *
 * Allowed rather than forbidden: each segment is letters, digits, dot, dash or
 * underscore, and does not start with a dot. That rules out "..", ".", hidden
 * files, empty segments, a leading slash, backslashes and control bytes without
 * listing any of them, which a blocklist would eventually fail to do.
 */
final class Key
{
    private const SEGMENT = '/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/D';

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/zip' => 'zip',
    ];

    private function __construct() {}

    /**
     * The key, if it is a path inside the disk.
     *
     * @throws InvalidKey
     */
    public static function valid(string $key): string
    {
        // An empty key is one empty segment, which the pattern refuses too.
        foreach (explode('/', $key) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw InvalidKey::of($key);
            }
        }

        return $key;
    }

    /**
     * A fresh key in $directory for bytes of the given type. Anything not in
     * the map is .bin, which no web server executes or renders inline.
     *
     * @throws InvalidKey
     */
    public static function fresh(string $directory, string $mimeType): string
    {
        return self::valid($directory) . '/' . bin2hex(random_bytes(16)) . '.' . self::extensionFor($mimeType);
    }

    public static function extensionFor(string $mimeType): string
    {
        return self::EXTENSIONS[strtolower($mimeType)] ?? 'bin';
    }
}
