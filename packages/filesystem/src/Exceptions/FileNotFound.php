<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Exceptions;

use RuntimeException;

/**
 * A well-formed key with no file behind it. Distinct from {@see InvalidKey}:
 * this one is a 404 to whoever asked, the other is somebody probing the disk.
 */
final class FileNotFound extends RuntimeException
{
    public static function at(string $key): self
    {
        return new self(sprintf('No file is stored at "%s".', $key));
    }
}
