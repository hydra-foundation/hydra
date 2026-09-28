<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

/** Cleanup for the tests that write to a real directory. */
final class TemporaryDirectory
{
    public static function remove(string $path): void
    {
        // Anything that is not a directory, a named pipe included.
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::remove($path . '/' . $entry);
        }

        rmdir($path);
    }
}
