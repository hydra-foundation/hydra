<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use finfo;
use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\FileNotFound;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
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
