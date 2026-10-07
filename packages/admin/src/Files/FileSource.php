<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use DateTimeImmutable;
use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Filesystem\Disks;
use Hydra\Filesystem\StoredFile;

/**
 * Every stored file as a row: what it is, how big, on which disk, and what
 * uses it. Read fresh from the disks and the references on every request, so
 * there is no table to fall out of step with either.
 *
 * The whole listing is built to sort it, so a page costs every file on both
 * disks plus one read of every file column. That is the price of having no
 * table, and fine at the scale an admin browses.
 *
 * A file in use is never deleted from here: the row that uses it is where it
 * goes, and that row's own form already takes the file with it.
 */
final class FileSource implements SourceInterface, RowSourceInterface, DeleteSourceInterface, FileHolderInterface, DescribesColumnsInterface
{
    public const IN_USE = 'in_use';
    public const ORPHAN = 'orphan';
    public const NEW = 'new';

    /** Every column a row has, in the order {@see row()} builds them. */
    public const COLUMNS = [
        'id', 'key', 'preview', 'download', 'disk', 'name', 'type', 'kind',
        'size', 'modified_at', 'status', 'uses', 'used_by',
    ];

    /** @var array<string, true> */
    private const ARCHIVES = [
        'application/zip' => true,
        'application/gzip' => true,
        'application/x-gzip' => true,
        'application/x-tar' => true,
        'application/x-bzip2' => true,
        'application/x-xz' => true,
        'application/x-7z-compressed' => true,
        'application/vnd.rar' => true,
        'application/x-rar' => true,
    ];

    public function __construct(
        private readonly Disks $disks,
        private readonly FileReferences $references,
        private readonly ModuleRegistry $modules,
    ) {}

    public function page(Criteria $criteria): Page
    {
        [$rows, $bytes] = $this->listing();

        $note = sprintf(
            '%d %s · %s (private %s, public %s)',
            count($rows),
            count($rows) === 1 ? 'file' : 'files',
            Readable::bytes(array_sum($bytes)),
            Readable::bytes($bytes[Disks::PRIVATE]),
            Readable::bytes($bytes[Disks::PUBLIC]),
        );

        $rows = array_values(array_filter($rows, static fn (array $row): bool => self::matches($row, $criteria)));
        self::sort($rows, $criteria);

        return new Page(array_slice($rows, $criteria->offset(), $criteria->perPage), count($rows), $criteria, $note);
    }

    /**
     * The storage at a glance, from the same one read a page makes: files and
     * bytes per disk, how many are orphans, and the newest few.
     *
     * @return array{
     *     disks: array<string, array{files: int, bytes: int}>,
     *     orphans: int,
     *     newest: list<array<string, mixed>>,
     * }
     */
    public function summary(int $newest = 5): array
    {
        [$rows, $bytes] = $this->listing();
        $disks = [];

        foreach ($bytes as $disk => $total) {
            $disks[$disk] = [
                'files' => count(array_filter($rows, static fn (array $row): bool => $row['disk'] === $disk)),
                'bytes' => $total,
            ];
        }

        self::sort($rows, new Criteria(sort: 'modified_at', direction: 'desc'));

        return [
            'disks' => $disks,
            'orphans' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === self::ORPHAN)),
            'newest' => array_slice($rows, 0, max(0, $newest)),
        ];
    }

    public function find(string $id): ?array
    {
        $qualified = FileId::qualified($id);

        if ($qualified === null) {
            return null;
        }

        [$disk, $key] = explode(':', $qualified, 2);

        if (FileReferences::isDerived($disk, $key)) {
            return null;
        }

        $directory = dirname($key);

        foreach ($this->disks->get($disk)->list($directory === '.' ? null : $directory) as $file) {
            if ($file->key === $key) {
                return $this->row($disk, $qualified, $file, $this->references->to($qualified));
            }
        }

        return null;
    }

    public function delete(string $id): void
    {
        $qualified = FileId::qualified($id) ?? throw WriteRejected::on('key', 'That is not a stored file.');
        // Asked now, not when the page was drawn: a row may have taken the
        // file up since.
        $uses = $this->references->to($qualified);

        if ($uses !== []) {
            $first = $this->label($uses[0]);
            $more = count($uses) - 1;

            throw WriteRejected::on('key', $more === 0
                ? "{$first} still uses this file. Remove it there first."
                : "{$first} and {$more} more still use this file. Remove them there first.");
        }

        [$disk, $key] = $this->disks->locate($qualified);
        // Not through Uploads, which logs a failure and carries on: here a
        // failure has to reach the admin, who asked for exactly this.
        $disk->delete($key);
    }

    /** For admin:check. There is no table: the "table" is the two disks. */
    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'disks',
            columns: self::COLUMNS,
            sortable: ['name', 'size', 'modified_at', 'uses'],
            searchable: ['name', 'key'],
            filterable: ['disk', 'kind', 'status', 'modified_at'],
            defaultSort: 'modified_at',
        );
    }

    /**
     * None. The Files module shows every file in an image or file field, which
     * would otherwise make it a holder of all of them, and walking it would
     * ask this source again.
     */
    public function references(): iterable
    {
        return [];
    }

    /**
     * Every file on both disks as a row, and the bytes on each disk: both disks
     * listed and every reference read, once.
     *
     * @return array{list<array<string, mixed>>, array<string, int>}
     */
    private function listing(): array
    {
        $uses = [];

        foreach ($this->references->all() as $reference) {
            $uses[$reference->key][] = $reference;
        }

        $rows = [];
        $bytes = [Disks::PRIVATE => 0, Disks::PUBLIC => 0];

        foreach ($bytes as $disk => $_) {
            foreach ($this->disks->get($disk)->list() as $file) {
                if (FileReferences::isDerived($disk, $file->key)) {
                    continue;
                }

                $qualified = $this->disks->qualify($disk, $file->key);
                $rows[] = $this->row($disk, $qualified, $file, $uses[$qualified] ?? []);
                $bytes[$disk] += $file->size;
            }
        }

        return [$rows, $bytes];
    }

    /**
     * @param list<Reference> $uses
     * @return array<string, mixed>
     */
    private function row(string $disk, string $qualified, StoredFile $file, array $uses): array
    {
        $name = null;

        foreach ($uses as $reference) {
            $name ??= $reference->name;
        }

        return [
            'id' => FileId::of($qualified),
            'key' => $qualified,
            // The key again, for an image field: only an image has a preview,
            // and anything else shows the field's icon rather than a broken
            // picture.
            'preview' => self::kind($file->mimeType) === 'image' ? $qualified : null,
            'download' => $qualified,
            'disk' => $disk,
            'name' => $name ?? basename($file->key),
            'type' => $file->mimeType,
            'kind' => self::kind($file->mimeType),
            'size' => $file->size,
            'modified_at' => (string) $file->modifiedAt->getTimestamp(),
            'status' => $uses !== [] ? self::IN_USE : ($this->references->isNew($file) ? self::NEW : self::ORPHAN),
            'uses' => count($uses),
            'used_by' => array_map(fn (Reference $reference): array => [
                'label' => $this->label($reference),
                'url' => $this->url($reference),
            ], $uses),
        ];
    }

    /** "Users #2 · avatar", or the holder's own name for one that is not a module. */
    private function label(Reference $reference): string
    {
        $label = $this->modules->find($reference->holder)->title ?? $reference->holder;

        if ($reference->row !== null) {
            $label .= ' #' . $reference->row;
        }

        return $reference->column === null ? $label : $label . ' · ' . $reference->column;
    }

    private function url(Reference $reference): ?string
    {
        $blueprint = $this->modules->find($reference->holder);

        return $blueprint === null || $reference->row === null
            ? null
            : $this->modules->rowUrl($blueprint, 'show', $reference->row);
    }

    private static function kind(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'image/') => 'image',
            $type === 'application/pdf' => 'pdf',
            str_starts_with($type, 'text/'), $type === 'application/json' => 'text',
            isset(self::ARCHIVES[$type]) => 'archive',
            default => 'other',
        };
    }

    /** @param array<string, mixed> $row */
    private static function matches(array $row, Criteria $criteria): bool
    {
        foreach ($criteria->filters as $column => $value) {
            if ((string) ($row[$column] ?? '') !== $value) {
                return false;
            }
        }

        $modified = $criteria->ranges['modified_at'] ?? null;

        if ($modified !== null && !$modified->contains(new DateTimeImmutable('@' . $row['modified_at']))) {
            return false;
        }

        return $criteria->search === null
            || stripos((string) $row['name'], $criteria->search) !== false
            || stripos((string) $row['key'], $criteria->search) !== false;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sort(array &$rows, Criteria $criteria): void
    {
        $column = $criteria->sort ?? 'modified_at';
        $numeric = $column === 'size' || $column === 'modified_at' || $column === 'uses';

        usort($rows, static function (array $a, array $b) use ($column, $numeric, $criteria): int {
            $order = $numeric
                ? (int) $a[$column] <=> (int) $b[$column]
                : strnatcasecmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? ''));

            // The key breaks a tie, so a page boundary never moves between requests.
            $order = $order !== 0 ? $order : strcmp((string) $a['key'], (string) $b['key']);

            return $criteria->direction === 'desc' ? -$order : $order;
        });
    }
}
