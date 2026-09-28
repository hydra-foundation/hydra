<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Filesystem\Contracts\StorageInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** A disk that stores and lists as usual and cannot delete: a read-only mount, say. */
final class UndeletableStorage implements StorageInterface
{
    public function __construct(private readonly StorageInterface $inner) {}

    public function put(string $directory, StreamInterface $contents): string
    {
        return $this->inner->put($directory, $contents);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function read(string $key): StreamInterface
    {
        return $this->inner->read($key);
    }

    public function size(string $key): int
    {
        return $this->inner->size($key);
    }

    public function mimeType(string $key): string
    {
        return $this->inner->mimeType($key);
    }

    public function delete(string $key): void
    {
        throw new RuntimeException('The disk is read-only.');
    }

    public function list(?string $directory = null): iterable
    {
        return $this->inner->list($directory);
    }
}
