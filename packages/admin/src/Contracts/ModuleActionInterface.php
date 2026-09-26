<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/**
 * What a button above a module's table does: to every row, or to none in
 * particular. The whole table, never the rows a filter is showing.
 */
interface ModuleActionInterface
{
    /**
     * Returns the sentence the visitor is told. Throw
     * {@see \Hydra\Admin\Exceptions\WriteRejected} to refuse.
     */
    public function run(): string;
}
