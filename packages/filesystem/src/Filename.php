<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

/**
 * The name a person sees a stored file called: what a download is saved under
 * and what a screen shows. It is never part of a key. A key is the disk's to
 * choose and says where the bytes are; this says what someone called them.
 *
 * The client's name is kept as they wrote it, unicode and all, less anything
 * that is not a name: a path in front of it, control bytes, stray whitespace.
 * Its extension is made to agree with the bytes, by adding the true one rather
 * than cutting the claimed one off, so "evil.php" holding a PNG is kept as
 * "evil.php.png": nothing the person typed is lost, and what the file opens as
 * is what it is.
 *
 * Making it safe inside a header is not done here. That is the header's job,
 * and a name cleaned for one place is wrong in every other.
 */
final class Filename
{
    /** What most filesystems allow a name. */
    public const MAX_BYTES = 255;

    /**
     * The extensions a person may reasonably have written for each one a key
     * gets. Anything else is a disagreement with the bytes.
     */
    private const ALIASES = [
        'jpg' => ['jpg', 'jpeg', 'jpe', 'jfif'],
        'txt' => ['txt', 'text'],
    ];

    private function __construct() {}

    /**
     * The name to keep for an upload of the given type, or null when the client
     * sent nothing that reads as one.
     */
    public static function clean(?string $name, string $mimeType): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = self::lastSegment(self::validUtf8($name));
        $name = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');

        if ($name === '') {
            return null;
        }

        return self::capped(self::withTrueExtension($name, $mimeType));
    }

    /**
     * Invalid bytes become U+FFFD, the way a browser shows them. Done with
     * htmlspecialchars' ENT_SUBSTITUTE and undone at once, which scrubs a string
     * without needing mbstring.
     */
    private static function validUtf8(string $name): string
    {
        if (preg_match('//u', $name) === 1) {
            return $name;
        }

        return htmlspecialchars_decode(
            htmlspecialchars($name, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ENT_NOQUOTES,
        );
    }

    /** Browsers send a bare name, old IE a Windows path, and a crafted request anything. */
    private static function lastSegment(string $name): string
    {
        $parts = preg_split('#[/\\\\]#', $name);

        return $parts === false ? $name : (string) end($parts);
    }

    private static function withTrueExtension(string $name, string $mimeType): string
    {
        $true = Key::extensionFor($mimeType);

        // .bin means the type is unknown: it says nothing true about the file,
        // so it has no business overruling what the person called it.
        if ($true === 'bin') {
            return $name;
        }

        $dot = strrpos($name, '.');
        $claimed = $dot === false ? '' : strtolower(substr($name, $dot + 1));

        return in_array($claimed, self::ALIASES[$true] ?? [$true], true) ? $name : $name . '.' . $true;
    }

    /** Cut to MAX_BYTES on a character boundary, keeping the extension. */
    private static function capped(string $name): string
    {
        if (strlen($name) <= self::MAX_BYTES) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : substr($name, $dot);

        if (strlen($extension) > 16) {
            $extension = '';
        }

        $stem = substr($name, 0, self::MAX_BYTES - strlen($extension));

        // A cut through a multibyte character leaves a lead byte on its own.
        while ($stem !== '' && preg_match('//u', $stem) !== 1) {
            $stem = substr($stem, 0, -1);
        }

        return rtrim($stem, ' .') . $extension;
    }
}
