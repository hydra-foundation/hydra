<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * Delete source interface
 *
 * How a module removes a row. Its own contract, like the others, so that being
 * correctable does not make a table disposable. Whether a particular row may go
 * is the source's call: throw {@see \Hydra\Admin\Exceptions\WriteRejected} and
 * the screen reports it instead of pretending the row is gone.
 */
interface DeleteSourceInterface
{
    public function delete(string $id): void;
}
