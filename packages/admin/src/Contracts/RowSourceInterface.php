<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * Row source interface
 *
 * How a module reads one row. Split from {@see UpdateSourceInterface} for the same
 * reason that one is split from {@see SourceInterface}: a screen that shows a
 * request log entry needs to look one up, and must not thereby be able to
 * rewrite it.
 */
interface RowSourceInterface
{
    /** The row at this id, or null when nothing has it. @return array<string, mixed>|null */
    public function find(string $id): ?array;
}
