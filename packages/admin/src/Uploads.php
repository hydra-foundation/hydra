<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Filesystem\Disks;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the admin does with a file: store an upload on the disk a control names,
 * and delete a stored file once nothing points at it. Anything else that takes
 * an upload (a settings screen, say) uses the same object, so there is one
 * place that decides how a file is kept.
 *
 * Keys come back qualified ("private:avatars/…"), which is what a column
 * stores: the disk travels with the key, so no reader has to be told it.
 */
final class Uploads
{
    public function __construct(
        private readonly Disks $disks,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function disks(): Disks
    {
        return $this->disks;
    }

    /** Store the upload and return its qualified key. */
    public function store(UploadedFileInterface $upload, string $directory, bool $public = false): string
    {
        $disk = $public ? Disks::PUBLIC : Disks::PRIVATE;

        return $this->disks->qualify($disk, $this->disks->get($disk)->put($directory, $upload->getStream()));
    }

    /** {@see store()}, for an {@see Input::file()} control. */
    public function storeFor(Input $control, UploadedFileInterface $upload): string
    {
        return $this->store($upload, $control->directory(), $control->isPublic());
    }

    /**
     * Delete a stored file, if there is one. Best effort: this runs after a
     * write has already landed, and a file that could not be removed is a stray
     * to log, never a reason to tell someone their save failed when it did not.
     */
    public function delete(mixed $qualified): void
    {
        if (!is_string($qualified) || $qualified === '') {
            return;
        }

        try {
            [$disk, $key] = $this->disks->locate($qualified);
            $disk->delete($key);
        } catch (Throwable $failure) {
            $this->logger?->warning('Could not delete a stored file.', [
                'key' => $qualified,
                'exception' => $failure,
            ]);
        }
    }
}
