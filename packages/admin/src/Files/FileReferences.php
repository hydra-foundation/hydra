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

/**
 * Which rows point at which stored files, found by reading them: every column a
 * module declares a file in, through the module's own source, plus whatever the
 * application's holders say. Nothing is recorded at upload, so there is no
 * table to fall out of step with the rows.
 */
final class FileReferences
{
    /** @param list<FileHolderInterface> $holders the application's own, beyond its modules */
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Disks $disks,
        private readonly array $holders = [],
    ) {}

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
