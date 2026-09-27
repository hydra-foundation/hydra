<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

/** Cleanup for the tests that write to a real directory. */
final class TemporaryDirectory
{
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
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
