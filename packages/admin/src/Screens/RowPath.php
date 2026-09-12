<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use LogicException;

/**
 * Shared by the screens that answer for one row. A path with no {id} in it
 * cannot name the row the source will be asked for, and the screen would
 * compile to a route that only ever 404s, so the complaint belongs at the call
 * that declared the path, not at the request that finally reached it.
 */
trait RowPath
{
    private static function rowPath(string $path): string
    {
        if (!str_contains($path, '{id}')) {
            throw new LogicException(sprintf(
                '%s answers for one row, so its path must carry {id}; "%s" does not.',
                static::class,
                $path,
            ));
        }

        return $path;
    }
}
