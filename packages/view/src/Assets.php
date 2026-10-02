<?php

declare(strict_types=1);

namespace Hydra\View;

/**
 * Fingerprinted URLs for the files under the public directory:
 * `/css/app.css` becomes `/css/app.3f2a1c9b0e.css`, the hash being the
 * file's bytes. The name changes whenever the file does, so the web server
 * can tell browsers to keep it for a year and never ask again; the server
 * strips the hash to find the file (see the skeleton's nginx config).
 *
 * Worked out on each request rather than read from a manifest: hashing the
 * largest file the skeleton links takes about a hundredth of a millisecond,
 * and a manifest would be one more deploy step and one more way to be stale.
 */
final class Assets
{
    /** Ten hex characters: enough that two versions of a file never share one. */
    private const HASH_LENGTH = 10;

    /** @var array<string, string> URLs already worked out, by path. */
    private array $urls = [];

    public function __construct(private readonly string $publicPath) {}

    /**
     * @throws AssetNotFound for a path that is not a root-relative file under
     *                       the public directory, saying which rule it broke
     */
    public function url(string $path): string
    {
        return $this->urls[$path] ??= $this->fingerprint($path);
    }

    private function fingerprint(string $path): string
    {
        $this->check($path);

        $file = $this->locate($path);
        $hash = substr(hash_file('xxh128', $file), 0, self::HASH_LENGTH);
        $dot = strrpos($path, '.');
        assert($dot !== false);

        return substr($path, 0, $dot) . '.' . $hash . substr($path, $dot);
    }

    /**
     * The rules a path can break before the filesystem is asked, each with its
     * own message, so a mistake reads as what it is rather than "not found".
     */
    private function check(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw new AssetNotFound('An asset path cannot contain a null byte.');
        }

        if (!str_starts_with($path, '/')) {
            throw new AssetNotFound(sprintf(
                'An asset path must start with "/", from the public directory: got "%s". '
                . 'Link a file on another host by its URL, without asset().',
                $path,
            ));
        }

        if (str_starts_with($path, '//')) {
            throw new AssetNotFound(sprintf(
                '"%s" names another host. Link it by its URL, without asset().',
                $path,
            ));
        }

        if (strpbrk($path, '?#') !== false) {
            throw new AssetNotFound(sprintf(
                '"%s" carries a query or fragment. Pass the file\'s path alone; the hash replaces any ?v=.',
                $path,
            ));
        }

        if (in_array('..', explode('/', $path), true)) {
            throw new AssetNotFound(sprintf('An asset path cannot contain "..": got "%s".', $path));
        }

        // A dot at the very start makes a dotfile (.env), not an extension.
        $name = basename($path);
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($name) - 1) {
            throw new AssetNotFound(sprintf(
                '"%s" has no extension. The hash goes before the extension, and the web server '
                . 'serves hashed names by extension, so a file without one cannot be fingerprinted.',
                $path,
            ));
        }
    }

    /** The file's real path, which must sit inside the public directory. */
    private function locate(string $path): string
    {
        $root = realpath($this->publicPath);
        if ($root === false || !is_dir($root)) {
            throw new AssetNotFound(sprintf(
                'The public path "%s" is not a directory. Pass the document root to Assets.',
                $this->publicPath,
            ));
        }

        $real = realpath($root . $path);
        if ($real === false || !is_file($real)) {
            throw new AssetNotFound(sprintf(
                '%s is not in %s. Check the path, or that the file was deployed.',
                $path,
                $root,
            ));
        }

        // A symlink that resolves outside is refused: the server would not
        // serve it from there, and the hash must describe what it serves.
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new AssetNotFound(sprintf('%s resolves outside %s.', $path, $root));
        }

        return $real;
    }
}
