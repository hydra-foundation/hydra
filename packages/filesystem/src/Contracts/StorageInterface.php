<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Contracts;

use Hydra\Filesystem\Exceptions\FileNotFound;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Psr\Http\Message\StreamInterface;

/**
 * A disk: somewhere bytes go in under a key and come back out by it.
 *
 * The key is the disk's to choose. A caller names a directory, and the disk
 * names the file from what the bytes turn out to be, so nothing a client sent
 * (a filename, an extension, a Content-Type) decides what a stored file is
 * called or how it is later served.
 *
 * Every key is checked before anything is opened: one that would reach outside
 * the disk throws {@see InvalidKey} from every method, delete() included.
 */
interface StorageInterface
{
    /**
     * Store the stream in $directory ("avatars", "blog/2026") and return the
     * key it is now known by: the directory, 32 random hex characters and an
     * extension for the detected type.
     *
     * @throws InvalidKey when the directory is not a path inside the disk
     */
    public function put(string $directory, StreamInterface $contents): string;

    public function exists(string $key): bool;

    /** @throws FileNotFound */
    public function read(string $key): StreamInterface;

    /** Bytes. @throws FileNotFound */
    public function size(string $key): int;

    /** Detected from the stored bytes, never recorded from the upload. @throws FileNotFound */
    public function mimeType(string $key): string;

    /** A key with nothing behind it is already deleted, so that is not an error. */
    public function delete(string $key): void;
}
