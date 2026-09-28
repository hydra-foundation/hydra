<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use DateTimeImmutable;

/**
 * One file on a disk, as {@see Contracts\StorageInterface::list()} found it.
 * The key is the disk's own, unqualified; {@see Disks::qualify()} adds the disk
 * for a caller that holds more than one.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $key,
        /** Bytes. */
        public int $size,
        /** Detected from the bytes, as mimeType() would say. */
        public string $mimeType,
        /** In UTC. */
        public DateTimeImmutable $modifiedAt,
    ) {}
}
