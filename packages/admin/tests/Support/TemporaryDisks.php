<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Filesystem\Disks;
use Hydra\Filesystem\LocalPublicStorage;
use Hydra\Filesystem\LocalStorage;
use Nyholm\Psr7\Factory\Psr17Factory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Both disks, real, in a directory of their own that the test removes. */
final class TemporaryDisks
{
    public readonly string $root;
    public readonly Disks $disks;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/hydra-admin-disks-' . bin2hex(random_bytes(6));
        $streams = new Psr17Factory;
        $this->disks = new Disks(
            new LocalStorage($this->root . '/private', $streams),
            new LocalPublicStorage($this->root . '/public', '/storage', $streams),
        );
    }

    /** How many files are on both disks together. */
    public function count(): int
    {
        if (!is_dir($this->root)) {
            return 0;
        }

        $count = 0;

        foreach ($this->files() as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach ($this->files(RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }

    /**
     * @param 0|1|2 $mode
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    private function files(int $mode = RecursiveIteratorIterator::LEAVES_ONLY): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            $mode,
        );
    }
}
