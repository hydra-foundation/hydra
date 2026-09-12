<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * How a module rewrites a row it already has. Extends {@see RowSourceInterface}
 * because an edit form has to read the row before it can offer it back.
 */
interface UpdateSourceInterface extends RowSourceInterface
{
    /** @param array<string, mixed> $data the validated subset, keyed by input name */
    public function update(string $id, array $data): void;
}
