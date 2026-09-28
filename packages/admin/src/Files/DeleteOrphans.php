<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Filesystem\Disks;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes every file nothing uses, once it is past the grace period.
 *
 * An orphan is a candidate, not a verdict: a row can take a file up while the
 * orphans are being found. So the references are read a second time after
 * the candidates are in hand, and only a file missing from both reads is
 * deleted. That is two passes over every file column, rather than one per
 * candidate.
 */
final class DeleteOrphans implements ModuleActionInterface
{
    public function __construct(
        private readonly FileReferences $references,
        private readonly Disks $disks,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function run(): string
    {
        $candidates = iterator_to_array($this->references->orphans(), false);

        if ($candidates === []) {
            return 'No orphaned files to delete.';
        }

        $inUse = [];

        foreach ($this->references->all() as $reference) {
            $inUse[$reference->key] = true;
        }

        $deleted = 0;
        $bytes = 0;
        $failed = 0;

        foreach ($candidates as $orphan) {
            if (isset($inUse[$orphan->qualified])) {
                continue;
            }

            try {
                [$disk, $key] = $this->disks->locate($orphan->qualified);
                $disk->delete($key);
                $deleted++;
                $bytes += $orphan->file->size;
                $this->logger?->info('Deleted an orphaned file.', ['key' => $orphan->qualified]);
            } catch (Throwable $failure) {
                $failed++;
                $this->logger?->warning('Could not delete an orphaned file.', [
                    'key' => $orphan->qualified,
                    'exception' => $failure,
                ]);
            }
        }

        if ($deleted === 0 && $failed === 0) {
            return 'No orphaned files to delete.';
        }

        $message = sprintf(
            'Deleted %d orphaned %s (%s).',
            $deleted,
            $deleted === 1 ? 'file' : 'files',
            Readable::bytes($bytes),
        );

        return $failed === 0 ? $message : $message . sprintf(' %d could not be deleted; the log says why.', $failed);
    }
}
