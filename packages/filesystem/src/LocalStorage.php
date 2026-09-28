<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use finfo;
use Generator;
use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\FileNotFound;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * A disk in a directory on this machine.
 *
 * The type is read from the start of the stream before anything is written, so
 * the file goes straight to its final key. Nobody can read it mid-copy, since
 * the key is random and not handed back until the copy is done, and a copy
 * that fails partway takes its half-file with it.
 */
class LocalStorage implements StorageInterface
{
    /** Enough of a file for any magic number finfo knows. */
    private const SNIFF = 65536;

    private readonly string $root;

    public function __construct(string $root, private readonly StreamFactoryInterface $streams)
    {
        $this->root = rtrim($root, '/');
    }

    public function put(string $directory, StreamInterface $contents): string
    {
        if ($contents->isSeekable()) {
            $contents->rewind();
        }

        $head = $contents->read(self::SNIFF);
        $key = Key::fresh($directory, $this->sniff($head));
        $path = $this->path($key);
        $this->ensureDirectory(dirname($path));

        $out = @fopen($path, 'xb');

        if ($out === false) {
            throw new RuntimeException(sprintf('Could not open "%s" for writing.', $key));
        }

        try {
            fwrite($out, $head);

            while (!$contents->eof()) {
                fwrite($out, $contents->read(self::SNIFF));
            }
        } catch (Throwable $failure) {
            fclose($out);
            unlink($path);

            throw $failure;
        }

        fclose($out);

        return $key;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path(Key::valid($key)));
    }

    public function read(string $key): StreamInterface
    {
        return $this->streams->createStreamFromFile($this->existing($key), 'rb');
    }

    public function size(string $key): int
    {
        return (int) filesize($this->existing($key));
    }

    public function mimeType(string $key): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($this->existing($key));

        return is_string($type) ? $type : 'application/octet-stream';
    }

    public function delete(string $key): void
    {
        $path = $this->path(Key::valid($key));

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function list(?string $directory = null): iterable
    {
        return $this->walk($directory === null ? $this->root : $this->path(Key::valid($directory)), $directory);
    }

    /** Where a key lives on this machine. Only ever called with a checked key. */
    private function path(string $key): string
    {
        return $this->root . '/' . $key;
    }

    private function existing(string $key): string
    {
        $path = $this->path(Key::valid($key));

        if (!is_file($path)) {
            throw FileNotFound::at($key);
        }

        return $path;
    }

    /**
     * Symlinks are never followed, not even the directory asked for: one could
     * lead out of the root. A name Key would refuse is not descended into, so a
     * hidden directory costs nothing, and a file that goes away mid-walk (a
     * delete() racing the listing) is passed over rather than thrown about.
     *
     * @return Generator<int, StoredFile>
     */
    private function walk(string $base, ?string $directory): Generator
    {
        if (!is_dir($base) || ($directory !== null && $this->throughLink($directory))) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $entry): bool => !$entry->isLink() && self::isKey($entry->getFilename()),
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        $types = new finfo(FILEINFO_MIME_TYPE);
        $utc = new DateTimeZone('UTC');

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $path = $entry->getPathname();

            // One stat for the lot, so there is no gap between "it is a file"
            // and "this is its size" for a delete() to fall into.
            $stat = @stat($path);

            if ($stat === false || ($stat['mode'] & 0o170000) !== 0o100000) {
                continue;
            }

            // A file deleted between the stat and here still gets its row: a
            // listing is a snapshot, as it is for one deleted a moment later.
            $type = @$types->file($path);

            yield new StoredFile(
                substr($path, strlen($this->root) + 1),
                $stat['size'],
                is_string($type) ? $type : 'application/octet-stream',
                (new DateTimeImmutable('@' . $stat['mtime']))->setTimezone($utc),
            );
        }
    }

    /** Whether any directory on the way down to $directory is a symlink. */
    private function throughLink(string $directory): bool
    {
        $path = $this->root;

        foreach (explode('/', $directory) as $segment) {
            $path .= '/' . $segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private static function isKey(string $key): bool
    {
        try {
            Key::valid($key);

            return true;
        } catch (InvalidKey) {
            return false;
        }
    }

    private function sniff(string $bytes): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($type) ? $type : 'application/octet-stream';
    }

    /**
     * Open to others for reading, as the files in it are by the umask: the web
     * server reads the public disk as a user of its own.
     */
    private function ensureDirectory(string $dir): void
    {
        // mkdir() fails on a directory that is already there, which is fine.
        if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create the directory "%s".', $dir));
        }
    }
}
