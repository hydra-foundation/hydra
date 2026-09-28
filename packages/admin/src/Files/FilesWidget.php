<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\ModuleRegistry;

/**
 * The dashboard's card for stored files: how many and how much on each disk,
 * how many nothing uses, and the newest few, each linked to the Files module.
 *
 * The module is found by its slug, "files" unless the application calls it
 * something else. With no such module the numbers still show, without links.
 */
final class FilesWidget implements PresenterInterface
{
    public function __construct(
        private readonly FileSource $files,
        private readonly ModuleRegistry $modules,
        private readonly string $module = 'files',
        private readonly int $newest = 5,
    ) {}

    public function present(): array
    {
        $summary = $this->files->summary($this->newest);
        $blueprint = $this->modules->find($this->module);
        $root = $blueprint === null ? null : $this->modules->root($blueprint);
        $total = 0;
        $bytes = 0;
        $disks = [];

        foreach ($summary['disks'] as $disk => $tally) {
            $total += $tally['files'];
            $bytes += $tally['bytes'];
            $disks[] = ['disk' => $disk, 'files' => $tally['files'], 'size' => Readable::bytes($tally['bytes'])];
        }

        return [
            'files' => $total,
            'size' => Readable::bytes($bytes),
            'disks' => $disks,
            'orphans' => $summary['orphans'],
            'url' => $root,
            'orphansUrl' => $root === null ? null : $root . '?status=' . FileSource::ORPHAN,
            'newest' => array_map(fn (array $row): array => [
                'name' => (string) $row['name'],
                'size' => Readable::bytes((int) $row['size']),
                'modified_at' => (string) $row['modified_at'],
                'url' => $blueprint === null ? null : $this->modules->rowUrl($blueprint, 'show', (string) $row['id']),
            ], $summary['newest']),
        ];
    }
}
