<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Exceptions;

use InvalidArgumentException;

/**
 * A key or directory that is not a path inside the disk. Keys usually arrive
 * from a URL or a stored row, so the message quotes it JSON-encoded: a NUL or a
 * newline in it shows up as what it is rather than breaking the log line.
 */
final class InvalidKey extends InvalidArgumentException
{
    public static function of(string $key): self
    {
        return new self(sprintf('"%s" is not a path inside the disk.', trim((string) json_encode($key), '"')));
    }
}
