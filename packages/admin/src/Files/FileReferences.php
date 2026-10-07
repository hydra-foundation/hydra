<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Generator;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Extractor;
use Hydra\Admin\FieldType;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Filesystem\Disks;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\StoredFile;
use Psr\Clock\ClockInterface;

/**
 * Which rows point at which stored files, found by reading them: every column a
 * module declares a file in, through the module's own source, plus whatever the
 * application's holders say. Nothing is recorded at upload, so there is no
 * table to fall out of step with the rows.
 */
final class FileReferences
{
    /**
     * How long a file referenced by nothing is given before it is called an
     * orphan. A form stores its file before the row that points at it is
     * written, so every upload is briefly referenced by nothing; one whose
     * request died in between is still found, a day later.
     */
    public const GRACE_SECONDS = 86_400;

    /** @param list<FileHolderInterface> $holders the application's own, beyond its modules */
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Disks $disks,
        private readonly ClockInterface $clock,
        private readonly array $holders = [],
        private readonly int $graceSeconds = self::GRACE_SECONDS,
    ) {}

    /**
     * What points at one qualified key. Every reference is read to answer it,
     * which is fine for a screen about one file and no way to answer many.
     *
     * @return list<Reference>
     */
    public function to(string $qualified): array
    {
        $found = [];

        foreach ($this->all() as $reference) {
            if ($reference->key === $qualified) {
                $found[] = $reference;
            }
        }

        return $found;
    }

    /**
     * Files on either disk that nothing points at and that are at least the
     * grace period old.
     *
     * An orphan is something that may be deleted, so this fails closed: every
     * reference is read before the first file is looked at, and a module or
     * holder that cannot be read throws before any orphan is yielded. A
     * partial set of references would turn files in use into orphans.
     *
     * An orphan is a candidate, not a verdict: a row written after the
     * references were read points at a file this still yields. Whatever deletes
     * one asks {@see to()} again first.
     *
     * @return Generator<int, Orphan>
     */
    /**
     * Whether a file is a copy the app made of another, not an upload:
     * hydrakit/image's resized pictures, under `variants/` on the public
     * disk. Nothing references them and they are not the admin's to tidy;
     * `image:variants --prune` removes the stale ones.
     */
    public static function isDerived(string $disk, string $key): bool
    {
        return $disk === Disks::PUBLIC && str_starts_with($key, 'variants/');
    }

    public function orphans(): Generator
    {
        $referenced = [];

        foreach ($this->all() as $reference) {
            $referenced[$reference->key] = true;
        }

        foreach ([Disks::PRIVATE, Disks::PUBLIC] as $disk) {
            foreach ($this->disks->get($disk)->list() as $file) {
                $qualified = $this->disks->qualify($disk, $file->key);

                if (!isset($referenced[$qualified]) && !$this->isNew($file) && !self::isDerived($disk, $file->key)) {
                    yield new Orphan($qualified, $file);
                }
            }
        }
    }

    /**
     * Every reference from every module and holder. A value that names no file
     * on either disk (empty, malformed, on a disk that does not exist) is not
     * a reference and is passed over.
     *
     * @return Generator<int, Reference>
     */
    public function all(): Generator
    {
        foreach ($this->modules->all() as $blueprint) {
            foreach ($this->fromModule($blueprint) as $reference) {
                yield $reference;
            }
        }

        foreach ($this->holders as $holder) {
            foreach ($this->valid($holder->references()) as $reference) {
                yield $reference;
            }
        }
    }

    /**
     * Whether a file is still inside the grace period: too young to be called
     * an orphan whatever points at it, since its row may not be written yet.
     */
    public function isNew(StoredFile $file): bool
    {
        return $file->modifiedAt->getTimestamp() > $this->clock->now()->getTimestamp() - $this->graceSeconds;
    }

    /** @return iterable<Reference> */
    private function fromModule(Blueprint $blueprint): iterable
    {
        $columns = $this->columns($blueprint);

        if ($columns === []) {
            return [];
        }

        $source = $this->modules->source($blueprint);

        if ($source instanceof FileHolderInterface) {
            return $this->valid($source->references());
        }

        return $this->walk($blueprint, (new Extractor($source))->rows(Criteria::defaults($blueprint), PHP_INT_MAX), $columns);
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, ?string> $columns
     * @return Generator<int, Reference>
     */
    private function walk(Blueprint $blueprint, iterable $rows, array $columns): Generator
    {
        $id = $blueprint->identifier();

        foreach ($rows as $row) {
            foreach ($columns as $column => $nameColumn) {
                $key = $row[$column] ?? null;

                if (!is_string($key) || !$this->namesAFile($key)) {
                    continue;
                }

                $name = $nameColumn === null ? null : ($row[$nameColumn] ?? null);
                $rowId = $id === null ? null : ($row[$id] ?? null);

                yield new Reference(
                    $key,
                    $blueprint->slug,
                    is_scalar($rowId) ? (string) $rowId : null,
                    $column,
                    is_string($name) && $name !== '' ? $name : null,
                );
            }
        }
    }

    /**
     * The columns a module keeps a file key in, each with the column its kept
     * name is in: every file control on its forms, and every image or file
     * field it shows, since a module can show files the application stored.
     *
     * @return array<string, ?string>
     */
    private function columns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->screens as $screen) {
            if (!$screen instanceof FormScreen) {
                continue;
            }

            foreach ($screen->controls() as $control) {
                if ($control->isFile()) {
                    $columns[$control->name()] = $control->nameColumn() ?? $columns[$control->name()] ?? null;
                }
            }
        }

        foreach ($blueprint->fields as $field) {
            if ($field->type() === FieldType::Image || $field->type() === FieldType::File) {
                $columns[$field->name()] = $columns[$field->name()] ?? $field->nameColumn();
            }
        }

        return $columns;
    }

    /**
     * @param iterable<Reference> $references
     * @return Generator<int, Reference>
     */
    private function valid(iterable $references): Generator
    {
        foreach ($references as $reference) {
            if ($this->namesAFile($reference->key)) {
                yield $reference;
            }
        }
    }

    private function namesAFile(string $qualified): bool
    {
        try {
            $this->disks->locate($qualified);

            return true;
        } catch (InvalidKey) {
            return false;
        }
    }
}
