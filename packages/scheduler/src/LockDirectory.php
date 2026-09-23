<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeInterface;
use RuntimeException;

/**
 * One lock file per task, held with flock(). The kernel lets go of it when the
 * holder exits however it exits, so a crash or a container restart cannot leave
 * a task locked out. The file itself stays: removing a lock file somebody else
 * has open is a race of its own.
 */
final class LockDirectory
{
    public function __construct(private readonly string $path) {}

    /** The lock, or null when a run that is still alive holds it. */
    public function acquire(string $name, DateTimeInterface $now): ?HeldLock
    {
        $handle = $this->open($name, 'c+');

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) $now->getTimestamp());
        fflush($handle);

        return new HeldLock($handle);
    }

    /** When the current or last holder started, as a Unix timestamp. */
    public function startedAt(string $name): ?int
    {
        $file = $this->file($name);
        $stamp = is_file($file) ? file_get_contents($file) : false;

        return is_string($stamp) && ctype_digit($stamp) ? (int) $stamp : null;
    }

    /** @return resource */
    private function open(string $name, string $mode)
    {
        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException("The scheduler cannot create its lock directory {$this->path}.");
        }

        $handle = @fopen($this->file($name), $mode);

        return $handle !== false
            ? $handle
            : throw new RuntimeException("The scheduler cannot open a lock in {$this->path}; check that it is writable.");
    }

    private function file(string $name): string
    {
        return $this->path . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $name) . '.lock';
    }
}
