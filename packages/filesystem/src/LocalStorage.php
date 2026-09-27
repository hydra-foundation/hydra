<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use finfo;
use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\FileNotFound;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A disk in a directory on this machine.
 *
 * A write goes to a temporary file beside its destination and is renamed into
 * place once its type is known, so a reader never sees half a file and a crash
 * mid-copy leaves nothing under a real key.
 */
class LocalStorage implements StorageInterface
{
    private readonly string $root;

    public function __construct(string $root, private readonly StreamFactoryInterface $streams)
    {
        $this->root = rtrim($root, '/');
    }

    public function put(string $directory, StreamInterface $contents): string
    {
        $dir = $this->path(Key::valid($directory));
        $this->ensureDirectory($dir);

        $temporary = $dir . '/.upload-' . bin2hex(random_bytes(8));
        $this->copy($contents, $temporary);

        try {
            $key = Key::fresh($directory, $this->detect($temporary));

            if (!rename($temporary, $this->path($key))) {
                throw new RuntimeException(sprintf('Could not move an upload into place at "%s".', $key));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

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
        return $this->detect($this->existing($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path(Key::valid($key));

        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Where a key lives on this machine. Only ever called with a checked key. */
    protected function path(string $key): string
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

    private function detect(string $path): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create the directory "%s".', $dir));
        }
    }

    private function copy(StreamInterface $contents, string $destination): void
    {
        $out = fopen($destination, 'xb');

        if ($out === false) {
            throw new RuntimeException(sprintf('Could not open "%s" for writing.', $destination));
        }

        try {
            if ($contents->isSeekable()) {
                $contents->rewind();
            }

            while (!$contents->eof()) {
                $chunk = $contents->read(65536);

                if ($chunk === '') {
                    break;
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
    }
}
